<?php

declare(strict_types=1);

namespace App\Domain\Channel;

use App\Integrations\Social\Contracts\ErrorKind;
use App\Integrations\Social\Contracts\PlatformError;
use App\Kernel\Database\Connection;
use App\Kernel\Security\Crypto;
use App\Support\Clock;
use App\Support\DbTime;

/** Durable dispatch fence plus per-attempt envelope. Transit wraps only a random ephemeral DEK before dispatch. */
final class OAuthRefreshRecovery
{
    public function __construct(private readonly Connection $db, private readonly Crypto $crypto, private readonly Clock $clock)
    {
    }

    /** @param array<string,mixed> $credential */
    public function resume(array $credential): ?string
    {
        $attempt = $this->db->select('SELECT * FROM oauth_refresh_attempts WHERE active_credential_id = ?', [(int) $credential['id']])[0] ?? null;
        if ($attempt === null) {
            return null;
        }
        if (!hash_equals((string) $attempt['input_fingerprint'], $this->fingerprint($credential))) {
            // Manual reconnection superseded the old input. Never apply its saved result to a new binding.
            $this->finish((int) $attempt['id'], 'superseded');
            return null;
        }
        if ($attempt['state'] === 'prepared') {
            $changed = $this->db->execute("UPDATE oauth_refresh_attempts SET state='abandoned_before_dispatch', active_credential_id=NULL, updated_at=? WHERE id=? AND state='prepared'", [DbTime::format($this->clock->now()), (int) $attempt['id']]);
            return $changed === 1 ? null : $this->resume($credential);
        }
        if ($attempt['state'] !== 'response_saved') {
            $this->uncertain((int) $attempt['id'], (int) $credential['id']);
        }
        $key = $this->crypto->decrypt((string) $attempt['recovery_key_enc']);
        try {
            $raw = base64_decode((string) $attempt['response_enc'], true);
            $json = is_string($raw) && strlen($raw) > SODIUM_CRYPTO_SECRETBOX_NONCEBYTES
                ? sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $key) : false;
        } finally {
            sodium_memzero($key);
        }
        $tokens = is_string($json) ? json_decode($json, true, 8, JSON_THROW_ON_ERROR) : null;
        if (!is_array($tokens) || !is_string($tokens['access'] ?? null) || !is_string($tokens['refresh'] ?? null) || !is_int($tokens['expires'] ?? null)) {
            $this->uncertain((int) $attempt['id'], (int) $credential['id']);
        }
        // All remote encryption is after the response is durable. A Transit outage leaves it recoverable.
        $access = $this->crypto->encrypt($tokens['access']);
        $refresh = $tokens['refresh'] === '' ? null : $this->crypto->encrypt($tokens['refresh']);
        $now = DbTime::format($this->clock->now());
        $this->db->transaction(function () use ($attempt, $credential, $tokens, $access, $refresh, $now): void {
            $row = $this->db->select('SELECT * FROM platform_credentials WHERE id = ? FOR UPDATE', [(int) $credential['id']])[0] ?? null;
            if ($row === null || !hash_equals($this->fingerprint($row), (string) $attempt['input_fingerprint'])) {
                $this->finish((int) $attempt['id'], 'superseded');
                throw new PlatformError(ErrorKind::Temporary, 'OAuth credential changed.', 'Доступ изменился. Повторим проверку.');
            }
            $this->db->execute(
                'UPDATE platform_credentials SET secret_enc=?, refresh_enc=?, expires_at=?, updated_at=? WHERE id=?',
                [$access, $refresh, DbTime::format(new \DateTimeImmutable('@' . $tokens['expires'])), $now, (int) $credential['id']]
            );
            $this->finish((int) $attempt['id'], 'completed');
        });
        return $tokens['access'];
    }

    /**
     * @param array<string,mixed> $credential
     * @return array{id:int,key:string}
     */
    public function prepare(array $credential): array
    {
        $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        try {
            $wrapped = $this->crypto->encrypt($key);
            $now = DbTime::format($this->clock->now());
            $id = $this->db->table('oauth_refresh_attempts')->insert([
                'credential_id' => (int) $credential['id'], 'active_credential_id' => (int) $credential['id'],
                'input_fingerprint' => $this->fingerprint($credential), 'state' => 'prepared', 'recovery_key_enc' => $wrapped,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            return ['id' => (int) $id, 'key' => $key];
        } catch (\Throwable $error) {
            sodium_memzero($key);
            throw $error;
        }
    }

    public function dispatch(int $id): void
    {
        if ($this->db->execute("UPDATE oauth_refresh_attempts SET state='dispatched', updated_at=? WHERE id=? AND state='prepared'", [DbTime::format($this->clock->now()), $id]) !== 1) {
            throw new PlatformError(ErrorKind::Temporary, 'OAuth refresh fence unavailable.', 'Обновление доступа уже выполняется.');
        }
    }

    /** @param array{access:string,refresh:string,expires:int} $tokens */
    public function save(int $id, #[\SensitiveParameter] string $key, #[\SensitiveParameter] array $tokens): void
    {
        $tokens['expires'] = $this->clock->now()->getTimestamp() + max(1, $tokens['expires']);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $encrypted = base64_encode($nonce . sodium_crypto_secretbox(json_encode($tokens, JSON_THROW_ON_ERROR), $nonce, $key));
        if ($this->db->execute("UPDATE oauth_refresh_attempts SET response_enc=?, state='response_saved', updated_at=? WHERE id=? AND state IN ('dispatched','recovery_required')", [$encrypted, DbTime::format($this->clock->now()), $id]) !== 1) {
            throw new PlatformError(ErrorKind::UnknownOutcome, 'OAuth response requires recovery.', 'Проверьте подключение канала.');
        }
    }

    public function finish(int $id, string $state): void
    {
        $this->db->execute('UPDATE oauth_refresh_attempts SET state=?, active_credential_id=NULL, updated_at=? WHERE id=?', [$state, DbTime::format($this->clock->now()), $id]);
    }

    public function uncertain(int $id, int $credentialId): never
    {
        $changed = $this->db->execute("UPDATE oauth_refresh_attempts SET state='recovery_required', updated_at=? WHERE id=? AND state IN ('dispatched','recovery_required')", [DbTime::format($this->clock->now()), $id]);
        $state = $changed === 1 ? 'recovery_required' : ($this->db->select('SELECT state FROM oauth_refresh_attempts WHERE id=?', [$id])[0]['state'] ?? null);
        if ($state !== 'recovery_required') {
            // A late observer must not destroy a receipt/completed result saved by the dispatch owner.
            throw new PlatformError(ErrorKind::Temporary, 'OAuth recovery state changed.', 'Обновление доступа сохранено. Повторите проверку.');
        }
        $message = 'Обновление доступа прервалось. Переподключите канал; старый refresh token повторно не используется.';
        $this->db->execute("UPDATE channels SET status='error', last_error=?, updated_at=? WHERE credential_id=?", [$message, DbTime::format($this->clock->now()), $credentialId]);
        throw new PlatformError(ErrorKind::Auth, 'OAuth refresh recovery required.', $message);
    }

    /** @param array<string,mixed> $credential */
    private function fingerprint(array $credential): string
    {
        return hash('sha256', json_encode([$this->crypto->decrypt((string) $credential['secret_enc']),
            is_string($credential['refresh_enc'] ?? null) ? $this->crypto->decrypt($credential['refresh_enc']) : null,
            $credential['device_id'] ?? null, $credential['workspace_id'], $credential['platform']], JSON_THROW_ON_ERROR));
    }
}
