<?php

declare(strict_types=1);

namespace App\Tests\Integration\Channel;

use App\Domain\Channel\Channel;
use App\Domain\Channel\ChannelCredentials;
use App\Domain\Channel\ChannelMode;
use App\Domain\Channel\ChannelRepository;
use App\Domain\Channel\CredentialVault;
use App\Domain\Channel\OAuthRefresher;
use App\Integrations\Social\Contracts\ErrorKind;
use App\Integrations\Social\Contracts\Platform;
use App\Integrations\Social\Contracts\PlatformError;
use App\Integrations\Social\Instagram\InstagramOAuth;
use App\Integrations\Social\Vk\VkOAuth;
use App\Kernel\Security\Crypto;
use App\Tests\Support\ChannelTestCase;
use App\Tests\Support\InstagramFixtures;
use App\Tests\Support\TestEnv;
use App\Tests\Support\VkFixtures;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(OAuthRefresher::class)]
#[CoversClass(ChannelCredentials::class)]
#[CoversClass(CredentialVault::class)]
#[CoversClass(VkOAuth::class)]
#[CoversClass(InstagramOAuth::class)]
final class OAuthRefresherTest extends ChannelTestCase
{
    private const TOKEN_URL = 'https://id.vk.com/oauth2/auth';

    /**
     * @return array{Channel, int, \App\Domain\Workspace\WorkspaceContext} the channel, its credential id and the context of its workspace
     */
    private function vkChannel(int $expiresInSeconds, string $external = '777'): array
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $context = $this->contextFor($workspace, $owner);
        $vault = $this->app->container()->get(CredentialVault::class);
        $credential = $vault->storeOAuth($context, Platform::Vk, 'ACCESS-OLD', 'REFRESH-OLD', $this->clock->now()->modify(sprintf('+%d seconds', $expiresInSeconds)), 'DEVICE-1', '12345', 'wall photos');
        $channel = $this->app->container()->get(ChannelRepository::class)->connect($context, Platform::Vk, $external, ChannelMode::Account, 'Сообщество', 'club', 'group', $credential, [], $owner->id)['channel'];

        return [$channel, $credential, $context];
    }

    /**
     * @return array{access: string, refresh: string, expires: string}
     */
    private function stored(int $credentialId): array
    {
        $row = $this->db->select('SELECT * FROM platform_credentials WHERE id = ?', [$credentialId])[0];
        $crypto = $this->app->container()->get(Crypto::class);

        return ['access' => $crypto->decrypt((string) $row['secret_enc']), 'refresh' => $crypto->decrypt((string) $row['refresh_enc']), 'expires' => (string) $row['expires_at']];
    }

    private function credentials(): ChannelCredentials
    {
        return $this->app->container()->get(ChannelCredentials::class);
    }

    public function testAFreshTokenIsUsedAsItIs(): void
    {
        [$channel] = $this->vkChannel(3600);

        $credential = $this->credentials()->forChannel($channel);

        self::assertSame(Platform::Vk, $credential->platform);
        self::assertSame('ACCESS-OLD', $credential->secret);
        self::assertSame([], $this->http->requests, 'no call to VK');
    }

    public function testATokenAboutToExpireIsRefreshedAndTheNewPairIsStored(): void
    {
        [$channel, $id] = $this->vkChannel(120);
        $this->http->expect('POST', self::TOKEN_URL, 200, VkFixtures::raw('oauth_tokens_refreshed'));

        $credential = $this->credentials()->forChannel($channel);

        self::assertSame('ACCESS-2', $credential->secret);
        $form = $this->http->requests[0]['options']['form_params'];
        self::assertSame('refresh_token', $form['grant_type']);
        self::assertSame('REFRESH-OLD', $form['refresh_token']);
        self::assertSame('DEVICE-1', $form['device_id']);
        self::assertSame('vk-test-app', $form['client_id']);
        $stored = $this->stored($id);
        self::assertSame('ACCESS-2', $stored['access']);
        self::assertSame('REFRESH-2', $stored['refresh'], 'the single-use refresh token is replaced at once');
        self::assertGreaterThan($this->clock->now()->modify('+50 minutes')->format('Y-m-d H:i:s'), $stored['expires']);

        // Used again: fresh now, no second refresh (the mock would fail on an unexpected request).
        self::assertSame('ACCESS-2', $this->credentials()->forChannel($channel)->secret);
        self::assertCount(1, $this->http->requests);
    }

    public function testAnExpiredTokenIsRefreshedToo(): void
    {
        [$channel] = $this->vkChannel(-3600);
        $this->http->expect('POST', self::TOKEN_URL, 200, VkFixtures::raw('oauth_tokens_refreshed'));

        self::assertSame('ACCESS-2', $this->credentials()->forChannel($channel)->secret);
    }

    public function testCommunitiesOfOneSignInShareTheRefresh(): void
    {
        [$first, $id, $context] = $this->vkChannel(10);
        $second = $this->app->container()->get(ChannelRepository::class)->connect($context, Platform::Vk, '888', ChannelMode::Account, 'Второе', null, 'group', $id, [], $context->userId)['channel'];
        $this->http->expect('POST', self::TOKEN_URL, 200, VkFixtures::raw('oauth_tokens_refreshed'));

        self::assertSame('ACCESS-2', $this->credentials()->forChannel($first)->secret);
        self::assertSame('ACCESS-2', $this->credentials()->forChannel($second)->secret);
        self::assertCount(1, $this->http->requests);
    }

    public function testARefusedRefreshTokenMeansReconnectAndKeepsTheStoredPair(): void
    {
        [$channel, $id] = $this->vkChannel(60);
        $this->http->expect('POST', self::TOKEN_URL, 400, VkFixtures::raw('oauth_error'));

        try {
            $this->credentials()->forChannel($channel);
            self::fail('expected an auth error');
        } catch (PlatformError $e) {
            self::assertSame(ErrorKind::Auth, $e->kind);
            self::assertTrue($e->concernsChannel());
            self::assertStringContainsString('заново', $e->forUser());
            self::assertStringNotContainsString('REFRESH-OLD', $e->getMessage());
        }
        self::assertSame('REFRESH-OLD', $this->stored($id)['refresh']);
    }

    public function testVkResponseFailureRequiresRecoveryWithoutReusingOneShotToken(): void
    {
        [$channel, $id] = $this->vkChannel(60);
        $this->http->expect('POST', self::TOKEN_URL, 503, '<html>down</html>');

        try {
            $this->credentials()->forChannel($channel);
            self::fail();
        } catch (PlatformError $e) {
            self::assertSame(ErrorKind::Auth, $e->kind);
            self::assertStringContainsString('Переподключите', $e->forUser());
        }
        self::assertSame('ACCESS-OLD', $this->stored($id)['access']);
    }

    public function testAnotherWorkerHoldingTheLockIsWaitedForThenGivenUpOn(): void
    {
        [$channel, $id] = $this->vkChannel(60);
        $redis = TestEnv::redis();
        $redis->set('oauth:refresh:' . $id, 'someone-else', ['nx', 'ex' => 30]);
        $refresher = new OAuthRefresher(
            $this->db,
            $this->app->container()->get(Crypto::class),
            $this->app->container()->get(VkOAuth::class),
            $this->app->container()->get(\App\Integrations\Social\Instagram\InstagramOAuth::class),
            $redis,
            $this->clock,
            new \Psr\Log\NullLogger(),
            1,
        );

        try {
            $refresher->accessToken($channel);
            self::fail();
        } catch (PlatformError $e) {
            self::assertSame(ErrorKind::Temporary, $e->kind);
        }
        self::assertSame([], $this->http->requests, 'the worker without the lock never calls VK');
        self::assertSame('someone-else', $redis->get('oauth:refresh:' . $id), 'a lock of somebody else is not released');
        $redis->del('oauth:refresh:' . $id);
    }

    public function testAChannelWithoutACredentialNeedsReconnecting(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $channel = $this->makeChannel($workspace, $owner, '777', 'VK', ChannelMode::Account, null, [], Platform::Vk);

        $this->expectException(PlatformError::class);
        $this->credentials()->forChannel($channel);
    }

    public function testStoredTokensNeverShowUpInDebugOutput(): void
    {
        $tokens = new \App\Integrations\Social\Vk\VkTokens('ACCESS-X', 'REFRESH-X', 3600, '1', 'wall');
        ob_start();
        var_dump($tokens);
        $dump = (string) ob_get_clean();

        self::assertStringNotContainsString('ACCESS-X', $dump);
        self::assertStringNotContainsString('REFRESH-X', $dump);
    }

    /**
     * @return array{Channel, int} an Instagram channel and its credential id; the token has `$expiresInSeconds` left
     */
    private function instagramChannel(int $expiresInSeconds): array
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $context = $this->contextFor($workspace, $owner);
        $vault = $this->app->container()->get(CredentialVault::class);
        $credential = $vault->storeOAuth($context, Platform::Instagram, 'IG-OLD', '', $this->clock->now()->modify(sprintf('+%d seconds', $expiresInSeconds)), '', InstagramFixtures::ACCOUNT, 'instagram_business_basic');
        $channel = $this->app->container()->get(ChannelRepository::class)->connect($context, Platform::Instagram, InstagramFixtures::ACCOUNT, ChannelMode::Account, 'Зерно', 'coffee_zerno', 'channel', $credential, [], $owner->id)['channel'];

        return [$channel, $credential];
    }

    private function storedInstagram(int $credentialId): string
    {
        $row = $this->db->select('SELECT * FROM platform_credentials WHERE id = ?', [$credentialId])[0];
        self::assertNull($row['refresh_enc'], 'Instagram has no refresh token');

        return $this->app->container()->get(Crypto::class)->decrypt((string) $row['secret_enc']);
    }

    public function testAnInstagramTokenWithMonthsLeftIsUsedAsItIs(): void
    {
        [$channel] = $this->instagramChannel(30 * 86400);

        self::assertSame('IG-OLD', $this->credentials()->forChannel($channel)->secret);
        self::assertSame([], $this->http->requests);
    }

    public function testAnInstagramTokenInItsLastWeekIsExtendedAndStored(): void
    {
        [$channel, $id] = $this->instagramChannel(3 * 86400);
        $this->http->expect('GET', 'https://graph.instagram.com/refresh_access_token', 200, InstagramFixtures::raw('token_refreshed'));

        $credential = $this->credentials()->forChannel($channel);

        self::assertSame('LONG-2', $credential->secret);
        self::assertSame('ig_refresh_token', $this->http->requests[0]['options']['query']['grant_type']);
        self::assertSame('IG-OLD', $this->http->requests[0]['options']['query']['access_token']);
        self::assertSame('LONG-2', $this->storedInstagram($id));
        $expires = (string) $this->db->select('SELECT expires_at FROM platform_credentials WHERE id = ?', [$id])[0]['expires_at'];
        self::assertGreaterThan($this->clock->now()->modify('+50 days')->format('Y-m-d H:i:s'), $expires);
    }

    public function testARevokedInstagramTokenMeansReconnectAndKeepsTheStoredOne(): void
    {
        [$channel, $id] = $this->instagramChannel(86400);
        $this->http->expect('GET', 'https://graph.instagram.com/refresh_access_token', 400, InstagramFixtures::raw('oauth_error'));

        try {
            $this->credentials()->forChannel($channel);
            self::fail('expected an auth error');
        } catch (PlatformError $e) {
            self::assertSame(ErrorKind::Auth, $e->kind);
            self::assertStringContainsString('заново', $e->forUser());
            self::assertStringNotContainsString('IG-OLD', $e->getMessage());
        }
        self::assertSame('IG-OLD', $this->storedInstagram($id));
    }

    public function testInstagramBeingDownIsTemporary(): void
    {
        [$channel] = $this->instagramChannel(86400);
        $this->http->expect('GET', 'https://graph.instagram.com/refresh_access_token', 503, 'down');

        try {
            $this->credentials()->forChannel($channel);
            self::fail();
        } catch (PlatformError $e) {
            self::assertSame(ErrorKind::Temporary, $e->kind);
        }
    }
    public function testTransitFailureBeforeDispatchMakesNoProviderRequest(): void
    {
        $backend = new \App\Tests\Support\FakeIsolatedCrypto();
        $this->app->container()->instance(Crypto::class, new Crypto('isolated', [], $backend));
        [$channel] = $this->vkChannel(60);
        $backend->failEncryptionAt = $backend->encryptCalls + 1;
        try {
            $this->credentials()->forChannel($channel);
            self::fail('Wrapping the recovery key must succeed before dispatch.');
        } catch (\App\Kernel\Exception\CryptoException) {
            self::assertSame([], $this->http->requests);
            self::assertSame([], $this->db->select('SELECT * FROM oauth_refresh_attempts'));
        }
    }

    public function testSavedEncryptedResponseSurvivesTransitFailureAndRestartWithoutSecondCall(): void
    {
        $backend = new \App\Tests\Support\FakeIsolatedCrypto();
        $crypto = new Crypto('isolated', [], $backend);
        $this->app->container()->instance(Crypto::class, $crypto);
        [$channel, $id] = $this->vkChannel(60);
        // The wrapping call succeeds; the first final credential encryption fails after durable receipt.
        $backend->failEncryptionAt = $backend->encryptCalls + 2;
        $this->http->expect('POST', self::TOKEN_URL, 200, VkFixtures::raw('oauth_tokens_refreshed'));
        try {
            $this->credentials()->forChannel($channel);
            self::fail('Expected a transient Transit outage after provider success.');
        } catch (\App\Kernel\Exception\CryptoException) {
            $attempt = $this->db->select('SELECT * FROM oauth_refresh_attempts')[0];
            self::assertSame('response_saved', $attempt['state']);
            self::assertStringNotContainsString('ACCESS-2', json_encode($attempt, JSON_THROW_ON_ERROR));
            self::assertStringNotContainsString('REFRESH-2', json_encode($attempt, JSON_THROW_ON_ERROR));
            self::assertSame('REFRESH-OLD', $this->stored($id)['refresh']);
        }
        // A fresh service instance models restart. It must consume the saved envelope, not VK's old token.
        $refresher = new OAuthRefresher(
            $this->db,
            $crypto,
            $this->app->container()->get(VkOAuth::class),
            $this->app->container()->get(InstagramOAuth::class),
            TestEnv::redis(),
            $this->clock,
            new \Psr\Log\NullLogger()
        );
        self::assertSame('ACCESS-2', $refresher->accessToken($channel));
        self::assertSame('ACCESS-2', $this->credentials()->forChannel($channel)->secret);
        self::assertCount(1, $this->http->requests);
        self::assertSame('REFRESH-2', $this->stored($id)['refresh']);
        self::assertSame('completed', $this->db->select('SELECT state FROM oauth_refresh_attempts')[0]['state']);
    }

    public function testCrashAfterDispatchWithoutReceiptFailsClosedAndNeverReusesRefreshToken(): void
    {
        [$channel, $id] = $this->vkChannel(60);
        $recovery = new \App\Domain\Channel\OAuthRefreshRecovery($this->db, $this->app->container()->get(Crypto::class), $this->clock);
        $row = $this->db->select('SELECT * FROM platform_credentials WHERE id=?', [$id])[0];
        $attempt = $recovery->prepare($row);
        $recovery->dispatch($attempt['id']);
        // Simulate death after dispatch/response but before durable receipt. Only after the bounded window is it uncertain.
        $this->clock->advance(\App\Domain\Channel\OAuthRefreshRecovery::DISPATCH_WINDOW_SECONDS);
        sodium_memzero($attempt['key']);
        for ($retry = 0; $retry < 2; ++$retry) {
            try {
                $this->credentials()->forChannel($channel);
                self::fail('Uncertain one-shot refresh must require reconnect.');
            } catch (PlatformError $error) {
                self::assertSame(ErrorKind::Auth, $error->kind);
                self::assertStringNotContainsString('REFRESH-OLD', $error->getMessage());
            }
        }
        self::assertSame([], $this->http->requests);
        self::assertSame('recovery_required', $this->db->select('SELECT state FROM oauth_refresh_attempts')[0]['state']);
        self::assertSame('error', $this->db->select('SELECT status FROM channels WHERE id=?', [$channel->id])[0]['status']);
    }

    public function testCrashBeforeDispatchCanRetryAndExplicitProviderRejectionHasNoSavedResponse(): void
    {
        [$channel, $id] = $this->vkChannel(60);
        $recovery = new \App\Domain\Channel\OAuthRefreshRecovery($this->db, $this->app->container()->get(Crypto::class), $this->clock);
        $attempt = $recovery->prepare($this->db->select('SELECT * FROM platform_credentials WHERE id=?', [$id])[0]);
        sodium_memzero($attempt['key']);
        $this->http->expect('POST', self::TOKEN_URL, 400, VkFixtures::raw('oauth_error'));
        try {
            $this->credentials()->forChannel($channel);
            self::fail('Provider refusal must remain a safe explicit refusal.');
        } catch (PlatformError $error) {
            self::assertSame(ErrorKind::Auth, $error->kind);
        }
        $rows = $this->db->select('SELECT * FROM oauth_refresh_attempts ORDER BY id');
        self::assertSame(['abandoned_before_dispatch', 'provider_rejected'], array_column($rows, 'state'));
        self::assertSame([null, null], array_column($rows, 'active_credential_id'));
        self::assertSame([null, null], array_column($rows, 'response_enc'));
        self::assertCount(1, $this->http->requests);
    }

    public function testLateDispatchReceiptCanRecoverButLateUncertainObserverCannotDestroyIt(): void
    {
        [$channel, $id] = $this->vkChannel(60);
        $recovery = new \App\Domain\Channel\OAuthRefreshRecovery($this->db, $this->app->container()->get(Crypto::class), $this->clock);
        $row = $this->db->select('SELECT * FROM platform_credentials WHERE id=?', [$id])[0];
        $attempt = $recovery->prepare($row);
        $recovery->dispatch($attempt['id']);
        try {
            $recovery->uncertain($attempt['id'], $id);
        } catch (PlatformError $error) {
            self::assertSame(ErrorKind::Auth, $error->kind);
        }
        // A still-running dispatch owner may persist its genuine response after its Redis lease expired.
        $recovery->save($attempt['id'], $attempt['key'], ['access' => 'LATE-ACCESS', 'refresh' => 'LATE-REFRESH', 'expires' => 3600]);
        sodium_memzero($attempt['key']);
        foreach (['response_saved', 'completed'] as $state) {
            if ($state === 'completed') {
                self::assertSame('LATE-ACCESS', $this->credentials()->forChannel($channel)->secret);
                self::assertSame('active', $this->db->select('SELECT status FROM channels WHERE id=?', [$channel->id])[0]['status']);
                self::assertNull($this->db->select('SELECT last_error FROM channels WHERE id=?', [$channel->id])[0]['last_error']);
            }
            try {
                $recovery->uncertain($attempt['id'], $id);
            } catch (PlatformError $error) {
                self::assertSame(ErrorKind::Temporary, $error->kind);
            }
            self::assertSame($state, $this->db->select('SELECT state FROM oauth_refresh_attempts')[0]['state']);
        }
        self::assertSame([], $this->http->requests);
    }

    public function testMigrationRollbackRefusesAnUnresolvedOneShotAttempt(): void
    {
        [, $id] = $this->vkChannel(60);
        $recovery = new \App\Domain\Channel\OAuthRefreshRecovery($this->db, $this->app->container()->get(Crypto::class), $this->clock);
        $attempt = $recovery->prepare($this->db->select('SELECT * FROM platform_credentials WHERE id=?', [$id])[0]);
        $recovery->dispatch($attempt['id']);
        sodium_memzero($attempt['key']);
        $migration = require dirname(__DIR__, 3) . '/database/migrations/2026_10_21_000047_oauth_refresh_recovery.php';
        try {
            $migration->down($this->db);
            self::fail('Unresolved receipts/fences must prevent destructive schema rollback.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('Resolve pending OAuth recovery', $error->getMessage());
        }
        self::assertSame('dispatched', $this->db->select('SELECT state FROM oauth_refresh_attempts')[0]['state']);
        // This synthetic dispatch never reached a provider; leave subsequent migration tests a clean fixture.
        $this->db->execute('DELETE FROM oauth_refresh_attempts WHERE id=?', [$attempt['id']]);
    }


    public function testExpiredRedisLeaseDoesNotDeclareFreshDispatchUncertainOrCallProviderTwice(): void
    {
        [$channel, $id] = $this->vkChannel(60);
        $recovery = new \App\Domain\Channel\OAuthRefreshRecovery($this->db, $this->app->container()->get(Crypto::class), $this->clock);
        $row = $this->db->select('SELECT * FROM platform_credentials WHERE id=?', [$id])[0];
        $attempt = $recovery->prepare($row);
        $recovery->dispatch($attempt['id']);
        // A's 30s lease has expired; B can acquire Redis, but the durable dispatch fence is still live.
        $this->clock->advance(31);
        TestEnv::redis()->del('oauth:refresh:' . $id);
        for ($retry = 0; $retry < 2; ++$retry) {
            try {
                $this->credentials()->forChannel($channel);
                self::fail('B must retry, never require reconnect or dispatch the one-time token again.');
            } catch (PlatformError $error) {
                self::assertSame(ErrorKind::Temporary, $error->kind);
                self::assertFalse($error->concernsChannel());
            }
        }
        self::assertSame('dispatched', $this->db->select('SELECT state FROM oauth_refresh_attempts')[0]['state']);
        self::assertSame('active', $this->db->select('SELECT status FROM channels WHERE id=?', [$channel->id])[0]['status']);
        self::assertSame([], $this->http->requests);
        // A finally receives the sole provider response. B recovers it without redispatch, and subsequent calls stay fresh.
        $recovery->save($attempt['id'], $attempt['key'], ['access' => 'ACCESS-NEW', 'refresh' => 'REFRESH-NEW', 'expires' => 3600]);
        sodium_memzero($attempt['key']);
        self::assertSame('ACCESS-NEW', $this->credentials()->forChannel($channel)->secret);
        self::assertSame('ACCESS-NEW', $this->app->container()->get(OAuthRefresher::class)->accessToken($channel));
        self::assertSame('REFRESH-NEW', $this->stored($id)['refresh']);
        self::assertSame('completed', $this->db->select('SELECT state FROM oauth_refresh_attempts')[0]['state']);
        self::assertSame('active', $this->db->select('SELECT status FROM channels WHERE id=?', [$channel->id])[0]['status']);
        self::assertSame([], $this->http->requests);
    }

    public function testDispatchWindowUsesDispatchTimeNotUpdatedAtAndPreservesUnrelatedError(): void
    {
        [$channel, $id] = $this->vkChannel(60);
        $recovery = new \App\Domain\Channel\OAuthRefreshRecovery($this->db, $this->app->container()->get(Crypto::class), $this->clock);
        $row = $this->db->select('SELECT * FROM platform_credentials WHERE id=?', [$id])[0];
        $attempt = $recovery->prepare($row);
        $recovery->dispatch($attempt['id']);
        $this->clock->advance(\App\Domain\Channel\OAuthRefreshRecovery::DISPATCH_WINDOW_SECONDS);
        $this->db->execute('UPDATE oauth_refresh_attempts SET updated_at=? WHERE id=?', [\App\Support\DbTime::format($this->clock->now()), $attempt['id']]);
        try {
            $this->credentials()->forChannel($channel);
            self::fail('Elapsed dispatch window must require recovery, even if updated_at is fresh.');
        } catch (PlatformError $error) {
            self::assertSame(ErrorKind::Auth, $error->kind);
        }
        self::assertSame('error', $this->db->select('SELECT status FROM channels WHERE id=?', [$channel->id])[0]['status']);
        $this->db->execute("UPDATE channels SET last_error='Unrelated newer channel error' WHERE id=?", [$channel->id]);
        $recovery->save($attempt['id'], $attempt['key'], ['access' => 'ACCESS-NEW', 'refresh' => 'REFRESH-NEW', 'expires' => 3600]);
        sodium_memzero($attempt['key']);
        self::assertSame('ACCESS-NEW', $this->credentials()->forChannel($channel)->secret);
        $channelRow = $this->db->select('SELECT status, last_error FROM channels WHERE id=?', [$channel->id])[0];
        self::assertSame(['status' => 'error', 'last_error' => 'Unrelated newer channel error'], $channelRow);
        self::assertSame([], $this->http->requests);
    }

    public function testOneShotProviderReceivesOneCallWhenSecondWorkerEntersDuringSlowDispatch(): void
    {
        [$channel, $id] = $this->vkChannel(60);
        $this->http->expect('POST', self::TOKEN_URL, 200, VkFixtures::raw('oauth_tokens_refreshed'));
        $competingWorker = function () use ($channel, $id): void {
            $this->clock->advance(31);
            // Model expiry of A's Redis lease, while A is suspended in the provider HTTP call.
            TestEnv::redis()->del('oauth:refresh:' . $id);
            try {
                $this->credentials()->forChannel($channel);
                self::fail('Competing B must be retryable.');
            } catch (PlatformError $error) {
                self::assertSame(ErrorKind::Temporary, $error->kind);
            }
            self::assertSame('active', $this->db->select('SELECT status FROM channels WHERE id=?', [$channel->id])[0]['status']);
        };
        $interleavedHttp = new class ($this->http, $competingWorker) implements \App\Kernel\HttpClient\HttpClientInterface {
            public function __construct(private readonly \App\Tests\Support\MockHttpClient $delegate, private readonly \Closure $competingWorker)
            {
            }

            public function request(string $method, string $url, array $options = []): \Psr\Http\Message\ResponseInterface
            {
                ($this->competingWorker)();
                return $this->delegate->request($method, $url, $options);
            }
        };
        $this->app->container()->instance(\App\Kernel\HttpClient\HttpClientInterface::class, $interleavedHttp);
        self::assertSame('ACCESS-2', $this->credentials()->forChannel($channel)->secret);
        self::assertSame('ACCESS-2', $this->app->container()->get(OAuthRefresher::class)->accessToken($channel));
        self::assertCount(1, $this->http->requests, 'one-time refresh token dispatched only by A');
        self::assertSame('REFRESH-2', $this->stored($id)['refresh']);
        self::assertSame('active', $this->db->select('SELECT status FROM channels WHERE id=?', [$channel->id])[0]['status']);
    }

    public function testMigrationBackfillsExistingDispatchAndAdoptsOnlyItsLegacyRecoveryError(): void
    {
        [$channel, $id] = $this->vkChannel(60);
        $recovery = new \App\Domain\Channel\OAuthRefreshRecovery($this->db, $this->app->container()->get(Crypto::class), $this->clock);
        $attempt = $recovery->prepare($this->db->select('SELECT * FROM platform_credentials WHERE id=?', [$id])[0]);
        $migration = require dirname(__DIR__, 3) . '/database/migrations/2026_10_24_000048_oauth_dispatch_window.php';
        $migration->down($this->db);
        try {
            $this->db->execute("UPDATE oauth_refresh_attempts SET state='recovery_required' WHERE id=?", [$attempt['id']]);
            $old = 'Обновление доступа прервалось. Переподключите канал; старый refresh token повторно не используется.';
            $this->db->execute("UPDATE channels SET status='error', last_error=? WHERE id=?", [$old, $channel->id]);
        } finally {
            $migration->up($this->db);
        }
        $row = $this->db->select('SELECT dispatch_started_at, updated_at FROM oauth_refresh_attempts WHERE id=?', [$attempt['id']])[0];
        self::assertSame($row['updated_at'], $row['dispatch_started_at']);
        $recovery->save($attempt['id'], $attempt['key'], ['access' => 'ACCESS-NEW', 'refresh' => 'REFRESH-NEW', 'expires' => 3600]);
        sodium_memzero($attempt['key']);
        self::assertSame('ACCESS-NEW', $this->credentials()->forChannel($channel)->secret);
        self::assertSame('active', $this->db->select('SELECT status FROM channels WHERE id=?', [$channel->id])[0]['status']);
    }

}
