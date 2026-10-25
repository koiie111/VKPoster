<?php

declare(strict_types=1);

namespace App\Tests\Integration\Channel;

use App\Domain\Channel\ChannelHealthService;
use App\Domain\Channel\ChannelMode;
use App\Domain\Channel\ChannelStatus;
use App\Domain\Channel\CredentialVault;
use App\Domain\Channel\OAuthRefreshRecovery;
use App\Domain\Post\Publisher;
use App\Integrations\Social\Contracts\ErrorKind;
use App\Integrations\Social\Contracts\Platform;
use App\Integrations\Social\Contracts\PlatformAdapter;
use App\Integrations\Social\Contracts\PlatformError;
use App\Integrations\Social\PlatformRegistry;
use App\Kernel\Security\Crypto;
use App\Support\DbTime;
use App\Tests\Support\PostTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;

/** Delayed Auth delivery enters the real health-check/Publisher handlers after another worker completes recovery. */
#[CoversNothing]
final class OAuthStaleErrorTest extends PostTestCase
{
    private ?int $attemptId = null;

    protected function tearDown(): void
    {
        try {
            // This fixture deliberately leaves uncertain attempts; do not leak one into migration rollback tests.
            if ($this->attemptId !== null) {
                $this->db->execute('DELETE FROM oauth_refresh_attempts WHERE id=?', [$this->attemptId]);
            }
        } finally {
            parent::tearDown();
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function pathsAndOutcomes(): iterable
    {
        foreach (['health', 'publisher'] as $path) {
            foreach (['completed', 'current', 'reconnected', 'superseded', 'replaced_in_place', 'ordinary_auth'] as $outcome) {
                yield $path . '/' . $outcome => [$path, $outcome];
            }
        }
    }

    #[DataProvider('pathsAndOutcomes')]
    public function testDelayedAuthCannotOverwriteNewerRecoveryOrBinding(string $path, string $outcome): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $context = $this->contextFor($workspace, $owner);
        $crypto = $this->app->container()->get(Crypto::class);
        $vault = $this->app->container()->get(CredentialVault::class);
        $id = $vault->storeOAuth($context, Platform::Vk, 'ACCESS-OLD', 'REFRESH-OLD', $this->in('+2 hours'), 'device', 'account', 'wall');
        $channel = $this->makeChannel($workspace, $owner, '777', 'OAuth test', ChannelMode::Account, $id, platform: Platform::Vk);
        $recovery = $this->app->container()->get(OAuthRefreshRecovery::class);
        $attempt = $recovery->prepare($this->db->select('SELECT * FROM platform_credentials WHERE id=?', [$id])[0]);
        $this->attemptId = $attempt['id'];
        $recovery->dispatch($attempt['id']);
        $this->clock->advance(OAuthRefreshRecovery::DISPATCH_WINDOW_SECONDS);

        // The adapter is only a delivery seam for a previously produced Auth, not an external provider.
        // Scheduling/claim, credential resolution, both error handlers, audit and guarded writes remain real.
        $adapter = $this->createMock(PlatformAdapter::class);
        $adapter->method('platform')->willReturn(Platform::Vk);
        $adapter->method('capabilities')->willReturn($this->fake->capabilities());
        $adapter->method('validate')->willReturn([]);
        $this->app->container()->instance(PlatformRegistry::class, new PlatformRegistry([$adapter], ['vk'], false));
        $publicationId = null;
        if ($path === 'publisher') {
            [, $publications] = $this->scheduled($context, [$channel], '+1 second');
            $publicationId = $publications[0]->id;
            $this->clock->advance(2);
            // Exercise Publisher's onPlatformError, not its earlier channel-health preflight.
            $this->db->execute('UPDATE channels SET last_health_at=? WHERE id=?', [DbTime::format($this->clock->now()), $channel->id]);
        }
        $expectedCredential = $id;
        $expectedAccess = 'ACCESS-OLD';
        $deliver = function () use ($recovery, $attempt, $id, $channel, $outcome, $context, $vault, $crypto, &$expectedCredential, &$expectedAccess): never {
            if ($outcome === 'ordinary_auth') {
                throw new PlatformError(ErrorKind::Auth, 'Ordinary provider refusal.', 'Доступ отозван.');
            }
            try {
                // B: obtain the real recovery-required Auth and suspend its delivery to the caller.
                $recovery->uncertain($attempt['id'], $id);
            } catch (PlatformError $error) {
                self::assertSame(ErrorKind::Auth, $error->kind);
                self::assertSame($attempt['id'], $error->oauthRecoveryAttemptId);
                self::assertSame('recovery_required', $this->db->select('SELECT state FROM oauth_refresh_attempts WHERE id=?', [$attempt['id']])[0]['state']);
            }
            if ($outcome === 'completed') {
                // A: persist late valid response and complete recovery BEFORE B handles its old Auth.
                $recovery->save($attempt['id'], $attempt['key'], ['access' => 'ACCESS-NEW', 'refresh' => 'REFRESH-NEW', 'expires' => 7200]);
                self::assertSame('ACCESS-NEW', $recovery->resume($this->db->select('SELECT * FROM platform_credentials WHERE id=?', [$id])[0]));
                $expectedAccess = 'ACCESS-NEW';
                self::assertSame('completed', $this->db->select('SELECT state FROM oauth_refresh_attempts WHERE id=?', [$attempt['id']])[0]['state']);
                self::assertSame(['status' => 'active', 'last_error' => null], $this->db->select('SELECT status, last_error FROM channels WHERE id=?', [$channel->id])[0]);
            } elseif ($outcome === 'reconnected') {
                $expectedCredential = $vault->storeOAuth($context, Platform::Vk, 'RECONNECTED', 'REFRESH-RECONNECTED', $this->in('+2 hours'), 'new-device', 'new-account', 'wall');
                $expectedAccess = 'RECONNECTED';
                $this->db->execute("UPDATE channels SET credential_id=?, status='active', last_error=NULL WHERE id=?", [$expectedCredential, $channel->id]);
            } elseif ($outcome === 'superseded') {
                $recovery->finish($attempt['id'], 'superseded');
                $this->db->execute("UPDATE channels SET status='active', last_error=NULL WHERE id=?", [$channel->id]);
            } elseif ($outcome === 'replaced_in_place') {
                $expectedAccess = 'REPLACED';
                $this->db->execute('UPDATE platform_credentials SET secret_enc=?, refresh_enc=? WHERE id=?', [$crypto->encrypt($expectedAccess), $crypto->encrypt('REFRESH-REPLACED'), $id]);
                $this->db->execute("UPDATE channels SET status='active', last_error=NULL WHERE id=?", [$channel->id]);
            }
            throw $error;
        };
        $adapter->expects(self::once())->method($path === 'health' ? 'healthCheck' : 'publish')->willReturnCallback($deliver);
        try {
            if ($path === 'health') {
                $this->app->container()->get(ChannelHealthService::class)->check($channel);
            } else {
                self::assertNotNull($publicationId);
                $this->app->container()->get(Publisher::class)->run($publicationId);
                self::assertSame('failed', $this->db->select('SELECT status FROM publications WHERE id=?', [$publicationId])[0]['status']);
            }
        } finally {
            sodium_memzero($attempt['key']);
        }
        $row = $this->db->select('SELECT status, last_error, credential_id FROM channels WHERE id=?', [$channel->id])[0];
        $mustFail = in_array($outcome, ['current', 'ordinary_auth'], true);
        self::assertSame($mustFail ? ChannelStatus::Error->value : ChannelStatus::Active->value, $row['status']);
        self::assertSame($expectedCredential, (int) $row['credential_id']);
        if ($mustFail) {
            self::assertNotNull($row['last_error']);
        } else {
            self::assertNull($row['last_error'], 'No stale reconnect prompt after completion/reconnect.');
        }
        self::assertSame($expectedAccess, $crypto->decrypt((string) $this->db->select('SELECT secret_enc FROM platform_credentials WHERE id=?', [$expectedCredential])[0]['secret_enc']));
        self::assertSame(match ($outcome) {
            'completed' => 'completed', 'superseded' => 'superseded', 'ordinary_auth' => 'dispatched', default => 'recovery_required',
        }, $this->db->select('SELECT state FROM oauth_refresh_attempts WHERE id=?', [$attempt['id']])[0]['state']);
        self::assertSame([], $this->http->requests);
    }
}
