<?php

declare(strict_types=1);

namespace App\Domain\Channel;

use App\Integrations\Social\Contracts\ErrorKind;
use App\Integrations\Social\Contracts\PlatformError;
use App\Integrations\Social\Instagram\InstagramOAuth;
use App\Integrations\Social\Vk\VkOAuth;
use App\Kernel\Database\Connection;
use App\Kernel\Security\Crypto;
use App\Support\Clock;
use App\Support\DbTime;
use Psr\Log\LoggerInterface;
use Redis;

/**
 * Keeps the OAuth access token of a credential fresh (VK: it lives an hour; Instagram: about 60 days, and it is extended itself, there is no refresh token). The refresh token is single use, so two workers must never
 * refresh the same credential at once: the one that gets the Redis lock refreshes and stores the new pair, the others wait for it and
 * read the result. Known pre-dispatch failures can retry; an ambiguous one-shot outcome requires reconnect.
 * Successful responses are durably encrypted before final Transit encryption and recover without provider redispatch.
 */
final class OAuthRefresher
{
    /** Refresh when fewer than this many seconds are left, so a long upload does not start with a token about to expire. */
    private readonly OAuthRefreshRecovery $recovery;
    private const MARGIN = 300;
    /** Instagram tokens last two months and can be extended only while valid: extend them in the last week. */
    private const INSTAGRAM_MARGIN = 7 * 86400;
    private const LOCK_SECONDS = 30;
    private const WAIT_SECONDS = 12;

    public function __construct(
        private readonly Connection $db,
        private readonly Crypto $crypto,
        private readonly VkOAuth $vk,
        private readonly InstagramOAuth $instagram,
        private readonly Redis $redis,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
        private readonly int $waitSeconds = self::WAIT_SECONDS,
    ) {
        $this->recovery = new OAuthRefreshRecovery($db, $crypto, $clock);
    }

    /**
     * The access token to publish with, refreshed first when it is about to expire.
     *
     * @throws PlatformError
     * @phpstan-impure Persists a refresh or consumes a durable recovery receipt.
     */
    public function accessToken(Channel $channel): string
    {
        $row = $this->row($channel);
        if ($row === null) {
            throw new PlatformError(ErrorKind::Auth, 'The channel has no stored credential.', 'Доступ к ' . $channel->platform->label() . ' не найден. Подключите канал заново.');
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
            $row = $this->row($channel) ?? throw new PlatformError(ErrorKind::Auth, 'The credential disappeared.', 'Доступ к ' . $channel->platform->label() . ' не найден. Подключите канал заново.');
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
        $recovered = $this->recovery->resume($row);
        if ($recovered !== null) {
            return $recovered;
        }
        $instagram = ($row['platform'] ?? null) === 'instagram';
        $refreshEnc = is_string($row['refresh_enc'] ?? null) ? $row['refresh_enc'] : '';
        $deviceId = is_string($row['device_id'] ?? null) ? $row['device_id'] : '';
        if (!$instagram && ($refreshEnc === '' || $deviceId === '')) {
            throw new PlatformError(ErrorKind::Auth, 'The credential cannot be refreshed.', 'Доступ закончился. Переподключите канал.');
        }
        $previous = $this->crypto->decrypt($instagram ? (string) $row['secret_enc'] : $refreshEnc);
        $attempt = $this->recovery->prepare($row);
        try {
            $this->recovery->dispatch($attempt['id']);
            try {
                $tokens = $instagram ? $this->instagram->refresh($previous) : $this->vk->refresh($previous, $deviceId);
            } catch (PlatformError $error) {
                if ($error->kind === ErrorKind::UnknownOutcome) {
                    $this->recovery->uncertain($attempt['id'], (int) $row['id']);
                }
                $this->recovery->finish($attempt['id'], 'provider_rejected');
                throw $error;
            }
            // No remote dependency between successful response and encrypted durable receipt.
            $this->recovery->save($attempt['id'], $attempt['key'], [
                'access' => $tokens->accessToken, 'refresh' => $tokens instanceof \App\Integrations\Social\Vk\VkTokens ? $tokens->refreshToken : '',
                'expires' => $tokens->expiresIn,
            ]);
        } finally {
            sodium_memzero($attempt['key']);
        }
        $result = $this->recovery->resume($row);
        if ($result === null) {
            throw new PlatformError(ErrorKind::Auth, 'OAuth refresh requires recovery.', 'Переподключите канал.');
        }
        $this->logger->info('oauth.refreshed', ['credential' => (int) $row['id']]);
        return $result;
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

        throw new PlatformError(ErrorKind::Temporary, 'Waited for a concurrent token refresh in vain.', $channel->platform->label() . ' обновляет доступ. Попробуем ещё раз чуть позже.');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function isFresh(array $row): bool
    {
        $expires = DbTime::parse($row['expires_at'] ?? null);
        $margin = ($row['platform'] ?? null) === 'instagram' ? self::INSTAGRAM_MARGIN : self::MARGIN;

        return $expires === null || $expires > $this->clock->now()->modify(sprintf('+%d seconds', $margin));
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
