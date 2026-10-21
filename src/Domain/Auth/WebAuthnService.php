<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\User\User;
use App\Kernel\Config;
use App\Kernel\Database\Connection;
use App\Kernel\Exception\HttpException;
use App\Support\Clock;
use App\Support\DbTime;
use lbuchs\WebAuthn\WebAuthn;

/** Hardware second factor for enrolled MFA users. Exact origin, durable one-use challenges and conditional counters. */
final class WebAuthnService
{
    public function __construct(private readonly Connection $db, private readonly Config $config, private readonly Clock $clock)
    {
    }

    /** @return list<array{id:int,label:string}> */
    public function credentials(User $user): array
    {
        return array_map(
            static fn (array $r): array => ['id' => (int) $r['id'], 'label' => (string) $r['label']],
            $this->db->select('SELECT id, label FROM webauthn_credentials WHERE user_id = ? AND revoked_at IS NULL ORDER BY id', [$user->id])
        );
    }

    /** @return array<string, mixed> Browser-ready public options; no credential private key exists on the server. */
    public function options(User $user, string $sessionId, bool $registration): array
    {
        if (!$user->hasTwoFactor() || $user->isBlocked()) {
            throw new HttpException(403, 'Сначала включите двухфакторную защиту.');
        }
        $rows = $this->db->select('SELECT credential_id FROM webauthn_credentials WHERE user_id = ? AND revoked_at IS NULL', [$user->id]);
        if (($registration && count($rows) >= 10) || (!$registration && $rows === [])) {
            throw new HttpException(422, 'Нет доступных ключей или достигнут лимит.');
        }
        $ids = array_map(static fn (array $r): string => (string) $r['credential_id'], $rows);
        $server = $this->server();
        $options = $registration
            ? $server->getCreateArgs((string) $user->id, $user->email ?? (string) $user->id, $user->name, 60, false, true, null, $ids)
            : $server->getGetArgs($ids, 60, true, true, true, true, true, true);
        $challenge = $server->getChallenge()->getBinaryString();
        $now = DbTime::format($this->clock->now());
        $this->db->execute('DELETE FROM webauthn_challenges WHERE expires_at < ?', [$now]);
        $this->db->execute(
            'INSERT INTO webauthn_challenges (user_id, session_hash, challenge_hash, purpose, expires_at) VALUES (?, ?, ?, ?, ?)',
            [$user->id, hash('sha256', $sessionId), hash('sha256', $challenge), $registration ? 'register' : 'login', DbTime::format($this->clock->now()->modify('+2 minutes'))]
        );
        $data = json_decode(json_encode($options, JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR);
        return is_array($data) ? $data : throw new HttpException(503, 'Ключ безопасности временно недоступен.');
    }

    /** @param array<string, mixed> $payload */
    public function register(User $user, string $sessionId, array $payload, string $label): void
    {
        if (!$user->hasTwoFactor() || $user->isBlocked() || count($this->credentials($user)) >= 10 || mb_strlen($label) < 1 || mb_strlen($label) > 80) {
            throw new HttpException(422, 'Не удалось добавить ключ безопасности.');
        }
        $client = $this->decode($payload['clientDataJSON'] ?? null, 8192);
        $challenge = $this->claim($user, $sessionId, $client, 'register');
        try {
            $result = $this->server()->processCreate($client, $this->decode($payload['attestationObject'] ?? null, 65536), $challenge, true, true);
            $id = $result->credentialId;
            $key = $result->credentialPublicKey;
            $counter = $result->signatureCounter ?? 0;
            if (!is_string($id) || strlen($id) > 1024 || $id === '' || !is_string($key) || !is_int($counter)) {
                throw new \RuntimeException('invalid credential');
            }
            $this->db->execute(
                'INSERT INTO webauthn_credentials (user_id, credential_id, credential_hash, public_key, sign_count, label, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$user->id, $id, hash('sha256', $id), $key, $counter, $label, DbTime::format($this->clock->now())]
            );
        } catch (\Throwable) {
            throw new HttpException(422, 'Не удалось проверить ключ безопасности.');
        }
    }

    /** @param array<string, mixed> $payload */
    public function verify(User $user, string $sessionId, array $payload): bool
    {
        if (!$user->hasTwoFactor() || $user->isBlocked()) {
            return false;
        }
        try {
            $client = $this->decode($payload['clientDataJSON'] ?? null, 8192);
            $challenge = $this->claim($user, $sessionId, $client, 'login');
            $id = $this->decode($payload['rawId'] ?? null, 1024);
            $rows = $this->db->select('SELECT id, public_key, sign_count FROM webauthn_credentials WHERE user_id = ? AND credential_hash = ? AND credential_id = ? AND revoked_at IS NULL', [$user->id, hash('sha256', $id), $id]);
            if ($rows === []) {
                return false;
            }
            $row = $rows[0];
            $auth = $this->decode($payload['authenticatorData'] ?? null, 4096);
            $server = $this->server();
            if ($server->processGet($client, $auth, $this->decode($payload['signature'] ?? null, 2048), (string) $row['public_key'], $challenge, (int) $row['sign_count'], true, true) !== true) {
                return false;
            }
            $counter = $server->getSignatureCounter() ?? 0;
            return $this->db->execute(
                'UPDATE webauthn_credentials SET sign_count = ?, last_used_at = ? WHERE id = ? AND user_id = ? AND revoked_at IS NULL AND sign_count = ?',
                [$counter, DbTime::format($this->clock->now()), (int) $row['id'], $user->id, (int) $row['sign_count']]
            ) === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    public function revoke(User $user, int $id): void
    {
        $this->db->execute('UPDATE webauthn_credentials SET revoked_at = ? WHERE id = ? AND user_id = ? AND revoked_at IS NULL', [DbTime::format($this->clock->now()), $id, $user->id]);
    }

    private function server(): WebAuthn
    {
        $origin = $this->config->string('app.url');
        $host = parse_url($origin, PHP_URL_HOST);
        if (!is_string($host) || (parse_url($origin, PHP_URL_SCHEME) !== 'https' && $host !== 'localhost')) {
            throw new HttpException(503, 'Ключ безопасности требует защищённого соединения.');
        }
        // No attestation identity/certification claim: none still proves credential possession and UV.
        return new WebAuthn($this->config->string('app.name'), $host, ['none', 'packed', 'fido-u2f'], true);
    }

    private function claim(User $user, string $sessionId, string $client, string $purpose): string
    {
        try {
            $data = json_decode($client, true, 16, JSON_THROW_ON_ERROR);
            $expected = rtrim($this->config->string('app.url'), '/');
            if (!is_array($data) || ($data['origin'] ?? '') !== $expected || ($data['crossOrigin'] ?? false) !== false
                || ($data['type'] ?? '') !== ($purpose === 'register' ? 'webauthn.create' : 'webauthn.get')) {
                throw new \RuntimeException('origin/type');
            }
            $challenge = $this->decode($data['challenge'] ?? null, 128);
            $now = DbTime::format($this->clock->now());
            if ($this->db->execute(
                'UPDATE webauthn_challenges SET consumed_at = ? WHERE user_id = ? AND session_hash = ? AND challenge_hash = ? AND purpose = ? AND consumed_at IS NULL AND expires_at > ?',
                [$now, $user->id, hash('sha256', $sessionId), hash('sha256', $challenge), $purpose, $now]
            ) !== 1) {
                throw new \RuntimeException('challenge');
            }
            return $challenge;
        } catch (\Throwable) {
            throw new HttpException(422, 'Запрос ключа устарел или не прошёл проверку.');
        }
    }

    private function decode(mixed $value, int $limit): string
    {
        if (!is_string($value) || strlen($value) > (int) ceil($limit * 4 / 3) + 4 || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
            throw new HttpException(422, 'Некорректный ответ ключа безопасности.');
        }
        $bytes = base64_decode(strtr($value, '-_', '+/'), true);
        return $bytes === false || strlen($bytes) > $limit ? throw new HttpException(422, 'Некорректный ответ ключа безопасности.') : $bytes;
    }
}
