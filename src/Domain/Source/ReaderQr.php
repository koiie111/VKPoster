<?php

declare(strict_types=1);

namespace App\Domain\Source;

use App\Kernel\Database\Connection;
use App\Kernel\Exception\HttpException;
use App\Kernel\Security\Crypto;
use Redis;

/** Short-lived workspace QR enrollment broker; Telegram sessions never pass through Redis or browser responses. */
final class ReaderQr
{
    private const TTL = 600;
    private const PREFIX = 'source:reader:qr:';
    private const PENDING = 'source:reader:qr:pending';

    public function __construct(private readonly Redis $redis, private readonly Connection $db, private readonly Crypto $crypto)
    {
    }

    /** Starts or replaces one workspace-owned QR attempt. */
    public function begin(string $accountId, int $workspaceId): void
    {
        $row = $this->owned($accountId, $workspaceId);
        if ($row['status'] === 'disabled') {
            throw new SourceException('Сначала включите аккаунт для чтения.', 'account');
        }
        $this->redis->eval("redis.call('DEL', KEYS[2]); redis.call('ZADD', KEYS[3], ARGV[3], ARGV[4]); return redis.call('SETEX', KEYS[1], ARGV[2], ARGV[1])", [$this->key($accountId), $this->imageKey($accountId), self::PENDING, bin2hex(random_bytes(32)), (string) self::TTL, (string) (time() + self::TTL), $accountId], 3);
    }

    /** @return array{state: string, image: ?string} */
    public function view(string $accountId, int $workspaceId): array
    {
        $this->owned($accountId, $workspaceId);
        $challenge = $this->redis->get($this->key($accountId));
        if (!is_string($challenge)) {
            return ['state' => 'expired', 'image' => null];
        }
        $value = $this->redis->get($this->imageKey($accountId));
        if ($value === 'password_needed') {
            return ['state' => 'password_needed', 'image' => null];
        }
        if ($value === 'waiting') {
            return ['state' => 'waiting', 'image' => null];
        }
        if ($value === 'error') {
            return ['state' => 'error', 'image' => null];
        }
        return ['state' => is_string($value) ? 'qr' : 'waiting', 'image' => is_string($value) ? $value : null];
    }

    /** @return list<array{id: string, challenge: string}> */
    public function pending(): array
    {
        $out = [];
        $this->redis->zRemRangeByScore(self::PENDING, '-inf', (string) time());
        foreach ($this->redis->zRange(self::PENDING, 0, -1) as $id) {
            $challenge = $this->redis->get($this->key($id));
            if (is_string($challenge)) {
                $out[] = ['id' => $id, 'challenge' => $challenge];
            }
        }
        return $out;
    }

    /** Accepts only an image for the current challenge; stale readers cannot replace a newer QR. */
    public function publish(string $accountId, string $challenge, string $image): void
    {
        $decoded = base64_decode($image, true);
        if (strlen($image) > 60000 || !is_string($decoded) || !str_starts_with($decoded, "\x89PNG\r\n\x1a\n")) {
            throw new HttpException(422, 'Invalid QR image');
        }
        $this->setCurrent($accountId, $challenge, $image);
    }

    public function passwordNeeded(string $accountId, string $challenge): void
    {
        $this->setCurrent($accountId, $challenge, 'password_needed');
    }

    public function waiting(string $accountId, string $challenge): void
    {
        $this->setCurrent($accountId, $challenge, 'waiting');
    }

    public function error(string $accountId, string $challenge): void
    {
        $this->setCurrent($accountId, $challenge, 'error');
    }

    /** Browser submits 2FA only for its own account; ciphertext lives for at most five minutes. */
    public function submitPassword(string $accountId, int $workspaceId, #[\SensitiveParameter] string $password): void
    {
        $this->owned($accountId, $workspaceId);
        if ($password === '' || strlen($password) > 256) {
            throw new SourceException('Введите пароль Telegram длиной до 256 символов.', 'password');
        }
        $challenge = $this->redis->get($this->key($accountId));
        if (!is_string($challenge) || $this->redis->get($this->imageKey($accountId)) !== 'password_needed') {
            throw new SourceException('Запрос на вход истёк. Начните подключение заново.', 'account');
        }
        $this->redis->setex($this->passwordKey($accountId, $challenge), 300, $this->crypto->encrypt($password, 'telegram-reader-qr-password'));
    }

    /** Returns a password to the trusted reader once; never includes it in an account listing. */
    public function takePassword(string $accountId, string $challenge): ?string
    {
        $this->assertCurrent($accountId, $challenge);
        $key = $this->passwordKey($accountId, $challenge);
        $cipher = $this->redis->eval("local v=redis.call('GET', KEYS[2]); if redis.call('GET', KEYS[1]) ~= ARGV[1] then return nil end; redis.call('DEL', KEYS[2]); return v", [$this->key($accountId), $key, $challenge], 2);
        if (!is_string($cipher)) {
            return null;
        }
        return $this->crypto->decrypt($cipher, 'telegram-reader-qr-password');
    }

    public function complete(string $accountId, string $challenge): void
    {
        $this->redis->eval("if redis.call('GET', KEYS[1]) == ARGV[1] then redis.call('DEL', KEYS[1], KEYS[2], KEYS[3]); redis.call('ZREM', KEYS[4], ARGV[2]) end", [$this->key($accountId), $this->imageKey($accountId), $this->passwordKey($accountId, $challenge), self::PENDING, $challenge, $accountId], 4);
    }

    public function assertChallenge(string $accountId, string $challenge): void
    {
        $this->assertCurrent($accountId, $challenge);
    }

    /** @return array<string, mixed> */
    private function owned(string $accountId, int $workspaceId): array
    {
        return $this->db->select('SELECT status FROM source_reader_accounts WHERE public_id=? AND workspace_id=?', [strtoupper($accountId), $workspaceId])[0] ?? throw new HttpException(404, 'Reader account not found');
    }

    private function setCurrent(string $accountId, string $challenge, string $value): void
    {
        $written = $this->redis->eval("if redis.call('GET', KEYS[1]) == ARGV[1] then return redis.call('SETEX', KEYS[2], ARGV[3], ARGV[2]) else return nil end", [$this->key($accountId), $this->imageKey($accountId), $challenge, $value, (string) self::TTL], 2);
        if ($written !== true && $written !== 'OK') {
            throw new HttpException(409, 'QR attempt expired');
        }
    }

    private function assertCurrent(string $accountId, string $challenge): void
    {
        $current = $this->redis->get($this->key($accountId));
        if (!is_string($current) || !hash_equals($current, $challenge)) {
            throw new HttpException(409, 'QR attempt expired');
        }
    }

    private function key(string $id): string
    {
        return self::PREFIX . $id;
    }

    private function imageKey(string $id): string
    {
        return self::PREFIX . $id . ':image';
    }

    private function passwordKey(string $id, string $challenge): string
    {
        return self::PREFIX . $id . ':password:' . $challenge;
    }
}
