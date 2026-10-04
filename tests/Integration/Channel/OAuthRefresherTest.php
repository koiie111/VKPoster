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
use App\Integrations\Social\Vk\VkOAuth;
use App\Kernel\Security\Crypto;
use App\Tests\Support\ChannelTestCase;
use App\Tests\Support\TestEnv;
use App\Tests\Support\VkFixtures;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(OAuthRefresher::class)]
#[CoversClass(ChannelCredentials::class)]
#[CoversClass(CredentialVault::class)]
#[CoversClass(VkOAuth::class)]
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

    public function testVkBeingDownLeavesThePairAloneAndIsTemporary(): void
    {
        [$channel, $id] = $this->vkChannel(60);
        $this->http->expect('POST', self::TOKEN_URL, 503, '<html>down</html>');

        try {
            $this->credentials()->forChannel($channel);
            self::fail();
        } catch (PlatformError $e) {
            self::assertSame(ErrorKind::Temporary, $e->kind);
            self::assertFalse($e->concernsChannel(), 'an outage must not mark the channel broken');
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
}
