<?php

declare(strict_types=1);

namespace App\Tests\Feature\Channel;

use App\Domain\Workspace\Role;
use App\Kernel\HttpClient\HttpClientInterface;
use App\Support\Clock;
use App\Tests\Support\ChannelTestCase;
use App\Tests\Support\TestEnv;

final class ShortVideoConnectTest extends ChannelTestCase
{
    private function enable(): void
    {
        $this->app = TestEnv::app([
            'PLATFORMS_ENABLED' => 'telegram,tiktok,youtube,fake',
            'TIKTOK_CLIENT_KEY' => 'tiktok-test', 'TIKTOK_CLIENT_SECRET' => 'not-real',
            'YOUTUBE_CLIENT_ID' => 'youtube-test', 'YOUTUBE_CLIENT_SECRET' => 'not-real',
        ]);
        $this->app->container()->instance(HttpClientInterface::class, $this->http);
        $this->app->container()->instance(Clock::class, $this->clock);
    }

    public function testOwnerSeesBothConnectionsAndOAuthUsesSeparateProviders(): void
    {
        $this->enable();
        [, $workspace] = $this->ownerSession();
        foreach (['tiktok' => 'www.tiktok.com', 'youtube' => 'accounts.google.com'] as $platform => $host) {
            $base = $this->channelsUrl($workspace, '/connect/' . $platform);
            self::assertSame(200, $this->get($base)->status);
            $start = $this->get($base . '/start');
            self::assertSame(302, $start->status);
            self::assertSame($host, parse_url((string) $start->header('Location'), PHP_URL_HOST));
        }
        self::assertSame([], $this->http->requests);
    }

    public function testViewerCannotConnectAndUnknownCallbackStateIsRejected(): void
    {
        $this->enable();
        [, $workspace] = $this->ownerSession();
        $this->actAsMember($workspace, 'viewer-short-video@example.test', Role::Viewer);
        foreach (['tiktok', 'youtube'] as $platform) {
            self::assertSame(403, $this->get($this->channelsUrl($workspace, '/connect/' . $platform))->status);
            self::assertSame(403, $this->get($this->channelsUrl($workspace, '/connect/' . $platform . '/start'))->status);
            self::assertSame(302, $this->get('/channels/connect/' . $platform . '/callback?state=forged&code=x')->status);
        }
        self::assertSame([], $this->http->requests);
    }

    public function testFeatureFlagsHideBothConnectionPages(): void
    {
        [, $workspace] = $this->ownerSession();
        foreach (['tiktok', 'youtube'] as $platform) {
            self::assertSame(404, $this->get($this->channelsUrl($workspace, '/connect/' . $platform))->status);
        }
    }

    public function testTikTokCallbackConnectsTheAuthorizedIdentityOnce(): void
    {
        $this->enable();
        [, $workspace] = $this->ownerSession();
        $start = $this->get($this->channelsUrl($workspace, '/connect/tiktok/start'));
        parse_str((string) parse_url((string) $start->header('Location'), PHP_URL_QUERY), $query);
        $state = $query['state'] ?? '';
        self::assertIsString($state);
        $this->http->expect('POST', 'https://open.tiktokapis.com/v2/oauth/token/', 200, '{"access_token":"access","refresh_token":"refresh","open_id":"creator-1","expires_in":7200,"scope":"user.info.basic,video.publish,video.list"}');
        $this->http->expect('POST', 'https://open.tiktokapis.com/v2/post/publish/creator_info/query/', 200, '{"data":{"creator_nickname":"Creator","creator_username":"creator"},"error":{"code":"ok"}}');
        $callback = '/channels/connect/tiktok/callback?state=' . rawurlencode($state) . '&code=fixture';
        self::assertSame(302, $this->get($callback)->status);
        self::assertSame('/app', $this->get($callback)->header('Location'));
        self::assertCount(2, $this->http->requests);
        $this->http->assertAllConsumed();
    }

    public function testYouTubeCallbackConnectsTheSelectedChannelOnce(): void
    {
        $this->enable();
        [, $workspace] = $this->ownerSession();
        $start = $this->get($this->channelsUrl($workspace, '/connect/youtube/start'));
        parse_str((string) parse_url((string) $start->header('Location'), PHP_URL_QUERY), $query);
        $state = $query['state'] ?? '';
        self::assertIsString($state);
        $this->http->expect('POST', 'https://oauth2.googleapis.com/token', 200, '{"access_token":"access","refresh_token":"refresh","expires_in":3600,"scope":"https://www.googleapis.com/auth/youtube.upload"}');
        $this->http->expect('GET', 'https://www.googleapis.com/youtube/v3/channels?part=snippet,contentDetails,statistics&mine=true', 200, '{"items":[{"id":"channel-1","snippet":{"title":"Creator"}}]}');
        $callback = '/channels/connect/youtube/callback?state=' . rawurlencode($state) . '&code=fixture';
        self::assertSame(302, $this->get($callback)->status);
        self::assertSame('/app', $this->get($callback)->header('Location'));
        self::assertCount(2, $this->http->requests);
        $this->http->assertAllConsumed();
    }
}
