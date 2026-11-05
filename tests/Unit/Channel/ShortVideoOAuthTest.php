<?php

declare(strict_types=1);

namespace App\Tests\Unit\Channel;

use App\Integrations\Social\Contracts\ErrorKind;
use App\Integrations\Social\Contracts\PlatformError;
use App\Integrations\Social\TikTok\TikTokOAuth;
use App\Integrations\Social\YouTube\YouTubeOAuth;
use App\Tests\Support\MockHttpClient;
use PHPUnit\Framework\TestCase;

final class ShortVideoOAuthTest extends TestCase
{
    public function testTikTokExchangeAndRotatingRefreshToken(): void
    {
        $http = new MockHttpClient();
        $oauth = new TikTokOAuth($http, 'key', 'secret');
        self::assertTrue($oauth->configured());
        $url = $oauth->authorizationUrl('https://app.example/callback', 'state');
        self::assertStringContainsString('state=state', $url);
        self::assertStringContainsString('video.publish', $url);
        $http->expect(
            'POST',
            'https://open.tiktokapis.com/v2/oauth/token/',
            200,
            '{"access_token":"access","refresh_token":"refresh-1","open_id":"creator","expires_in":7200,"scope":"video.publish,video.list"}'
        );
        self::assertSame('refresh-1', $oauth->exchange('code', 'https://app.example/callback')->refreshToken);
        $http->expect(
            'POST',
            'https://open.tiktokapis.com/v2/oauth/token/',
            200,
            '{"access_token":"new-access","refresh_token":"refresh-2","open_id":"creator","expires_in":7200,"scope":"video.publish,video.list"}'
        );
        self::assertSame('refresh-2', $oauth->refresh('refresh-1')->refreshToken);
        self::assertSame('refresh_token', $http->requests[1]['options']['form_params']['grant_type']);
        $http->assertAllConsumed();
    }

    public function testTikTokRefusesMissingPublishPermissionAndExpiredAccess(): void
    {
        $http = new MockHttpClient();
        $oauth = new TikTokOAuth($http, 'key', 'secret');
        $http->expect(
            'POST',
            'https://open.tiktokapis.com/v2/oauth/token/',
            200,
            '{"access_token":"access","refresh_token":"refresh","open_id":"creator","expires_in":7200,"scope":"video.list"}'
        );
        try {
            $oauth->exchange('code', 'https://app.example/callback');
            self::fail('Expected missing permission.');
        } catch (PlatformError $error) {
            self::assertSame(ErrorKind::Auth, $error->kind);
        }
        $http->expect('POST', 'https://open.tiktokapis.com/v2/oauth/token/', 400, '{"error":"invalid_grant"}');
        try {
            $oauth->refresh('expired');
            self::fail('Expected expired token.');
        } catch (PlatformError $error) {
            self::assertSame(ErrorKind::Auth, $error->kind);
        }
        $http->assertAllConsumed();
    }

    public function testYouTubeUsesPkceAndKeepsRefreshTokenWhenGoogleOmitsIt(): void
    {
        $http = new MockHttpClient();
        $oauth = new YouTubeOAuth($http, 'client', 'secret');
        self::assertTrue($oauth->configured());
        $url = $oauth->authorizationUrl('https://app.example/callback', 'state', 'challenge');
        self::assertStringContainsString('code_challenge=challenge', $url);
        self::assertStringContainsString('youtube.force-ssl', $url);
        $http->expect(
            'POST',
            'https://oauth2.googleapis.com/token',
            200,
            '{"access_token":"access","refresh_token":"refresh","expires_in":3600}'
        );
        self::assertSame('refresh', $oauth->exchange('code', 'https://app.example/callback', 'verifier')->refreshToken);
        self::assertSame('verifier', $http->requests[0]['options']['form_params']['code_verifier']);
        $http->expect('POST', 'https://oauth2.googleapis.com/token', 200, '{"access_token":"new-access","expires_in":3600}');
        self::assertSame('refresh', $oauth->refresh('refresh')->refreshToken);
        $http->assertAllConsumed();
    }

    public function testYouTubeRejectsMissingOfflineTokenAndRevocation(): void
    {
        $http = new MockHttpClient();
        $oauth = new YouTubeOAuth($http, 'client', 'secret');
        $http->expect('POST', 'https://oauth2.googleapis.com/token', 200, '{"access_token":"access","expires_in":3600}');
        try {
            $oauth->exchange('code', 'https://app.example/callback', 'verifier');
            self::fail('Expected missing offline token.');
        } catch (PlatformError $error) {
            self::assertSame(ErrorKind::Auth, $error->kind);
        }
        $http->expect('POST', 'https://oauth2.googleapis.com/token', 400, '{"error":"invalid_grant"}');
        try {
            $oauth->refresh('revoked');
            self::fail('Expected revoked token.');
        } catch (PlatformError $error) {
            self::assertSame(ErrorKind::Auth, $error->kind);
        }
        $http->assertAllConsumed();
    }
}
