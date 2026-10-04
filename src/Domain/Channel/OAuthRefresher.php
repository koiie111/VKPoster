<?php

declare(strict_types=1);

namespace App\Domain\Channel;

use App\Integrations\Social\Contracts\ErrorKind;
use App\Integrations\Social\Contracts\PlatformError;
use App\Integrations\Social\Vk\VkOAuth;
use App\Kernel\Database\Connection;
use App\Kernel\Security\Crypto;
use App\Support\Clock;
use App\Support\DbTime;
use Psr\Log\LoggerInterface;
use Redis;

/**
 * Keeps the OAuth access token of a credential fresh (VK: it lives an hour). The refresh token is single use, so two workers must never
 * refresh the same credential at once: the one that gets the Redis lock refreshes and stores the new pair, the others wait for it and
 * read the result. A refresh that VK refuses ends as an `auth` error (the person has to reconnect); one that could not reach VK is `temporary`
 * and leaves the stored pair untouched.
 */
final class OAuthRefresher
{
    /** Refresh when fewer than this many seconds are left, so a long upload does not start with a token about to expire. */
    private const MARGIN = 300;
    private const LOCK_SECONDS = 30;
    private const WAIT_SECONDS = 12;

    public function __construct(
        private readonly Connection $db,
        private readonly Crypto $crypto,
        private readonly VkOAuth $vk,
        private readonly Redis $redis,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
        private readonly int $waitSeconds = self::WAIT_SECONDS,
    ) {
    }

    /**
     * The access token to publish with, refreshed first when it is about to expire.
     *
     * @throws PlatformError
     */
    public function accessToken(Channel $channel): string
    {
        $row = $this->row($channel);
        if ($row === null) {
            throw new PlatformError(ErrorKind::Auth, 'The channel has no stored credential.', 'Доступ к ВКонтакте не найден. Подключите сообщество заново.');
        }
        if ($this->isFresh($row)) {
            return $this->crypto->decrypt((string) $row['secret_enc']);
        }

        $key = 'oauth:refresh:' . $row['id'];
        $owner = bin2hex(random_bytes(8));
        if ($this->redis->set($key, $owner, ['nx', 'ex' => self::LOCK_SECONDS]) !== true) {
            return $this->waitForOther($channel);
        }
        try {
            // Somebody may have refreshed between our first read and the lock.
            $row = $this->row($channel) ?? throw new PlatformError(ErrorKind::Auth, 'The credential disappeared.', 'Доступ к ВКонтакте не найден. Подключите сообщество заново.');
            if ($this->isFresh($row)) {
                return $this->crypto->decrypt((string) $row['secret_enc']);
            }

            return $this->refresh($row);
        } finally {
            $this->redis->eval('if redis.call("get", KEYS[1]) == ARGV[1] then return redis.call("del", KEYS[1]) else return 0 end', [$key, $owner], 1);
        }
    }

    /**
     * @param array<string, mixed> $row
     * @throws PlatformError
     */
    private function refresh(array $row): string
    {
        $refreshEnc = is_string($row['refresh_enc'] ?? null) ? $row['refresh_enc'] : '';
        $deviceId = is_string($row['device_id'] ?? null) ? $row['device_id'] : '';
        if ($refreshEnc === '' || $deviceId === '') {
            throw new PlatformError(ErrorKind::Auth, 'The credential cannot be refreshed.', 'Доступ к ВКонтакте закончился. Подключите сообщество заново.');
        }
        $tokens = $this->vk->refresh($this->crypto->decrypt($refreshEnc), $deviceId);
        $now = $this->clock->now();
        // The new pair is stored at once: the old refresh token is already dead.
        $this->db->table('platform_credentials')->where('id', '=', (int) $row['id'])->update([
            'secret_enc' => $this->crypto->encrypt($tokens->accessToken),
            'refresh_enc' => $this->crypto->encrypt($tokens->refreshToken),
            'expires_at' => DbTime::format($now->modify(sprintf('+%d seconds', $tokens->expiresIn))),
            'updated_at' => DbTime::format($now),
        ]);
        $this->logger->info('oauth.refreshed', ['credential' => (int) $row['id']]);

        return $tokens->accessToken;
    }

    /**
     * Another worker is refreshing: wait until its result is stored.
     *
     * @throws PlatformError
     */
    private function waitForOther(Channel $channel): string
    {
        $until = microtime(true) + $this->waitSeconds;
        while (microtime(true) < $until) {
            usleep(200_000);
            $row = $this->row($channel);
            if ($row !== null && $this->isFresh($row)) {
                return $this->crypto->decrypt((string) $row['secret_enc']);
            }
        }

        throw new PlatformError(ErrorKind::Temporary, 'Waited for a concurrent token refresh in vain.', 'ВКонтакте обновляет доступ. Попробуем ещё раз чуть позже.');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function isFresh(array $row): bool
    {
        $expires = DbTime::parse($row['expires_at'] ?? null);

        return $expires === null || $expires > $this->clock->now()->modify(sprintf('+%d seconds', self::MARGIN));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function row(Channel $channel): ?array
    {
        if ($channel->credentialId === null) {
            return null;
        }

        return $this->db->table('platform_credentials')->where('id', '=', $channel->credentialId)->where('workspace_id', '=', $channel->workspaceId)->first();
    }
}
