<?php

declare(strict_types=1);

namespace App\Tests\Unit\Channel;

use App\Integrations\Social\Contracts\ErrorKind;
use App\Integrations\Social\Contracts\PlatformError;
use App\Integrations\Social\TikTok\TikTokApi;
use App\Integrations\Social\YouTube\YouTubeApi;
use App\Tests\Support\MockHttpClient;
use PHPUnit\Framework\TestCase;

final class ShortVideoApiTest extends TestCase
{
    public function testYouTubeResumableUploadUsesConfirmedOffsetsAndNoTokenInUrl(): void
    {
        $http = new MockHttpClient();
        $api = new YouTubeApi($http);
        $session = 'https://www.googleapis.com/upload/youtube/v3/videos?upload_id=abc';
        $http->expect('POST', 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status', 200, '', ['Location' => $session]);
        $http->expect('PUT', $session, 308, '', ['Range' => 'bytes=0-3']);
        $http->expect('PUT', $session, 200, '{"id":"video-1"}');
        self::assertSame($session, $api->startUpload(['snippet' => ['title' => 'Short']], 8, 'secret'));
        self::assertSame(['id' => null, 'offset' => 4], $api->uploadStatus($session, 8, 'secret'));
        self::assertSame(['id' => 'video-1', 'offset' => 8], $api->uploadChunk($session, 'four', 4, 8, 'secret'));
        self::assertSame('bytes 4-7/8', $http->requests[2]['options']['headers']['Content-Range']);
        foreach ($http->requests as $request) {
            self::assertSame('Bearer secret', $request['options']['headers']['Authorization']);
            self::assertStringNotContainsString('secret', $request['url']);
        }
        $http->assertAllConsumed();
    }

    public function testYouTubeRejectsForeignResumableUrl(): void
    {
        $http = new MockHttpClient();
        $http->expect('POST', 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status', 200, '', ['Location' => 'https://evil.example/upload/youtube/v3/videos']);
        $this->expectException(PlatformError::class);
        (new YouTubeApi($http))->startUpload([], 8, 'secret');
    }

    public function testYouTubeSetsThumbnailAfterProcessingAndUpdatesMetadata(): void
    {
        $http = new MockHttpClient();
        $api = new YouTubeApi($http);
        $path = tempnam(sys_get_temp_dir(), 'thumb');
        self::assertIsString($path);
        file_put_contents($path, 'jpeg-fixture');
        try {
            $http->expect('POST', 'https://www.googleapis.com/upload/youtube/v3/thumbnails/set?videoId=video-1&uploadType=media', 200, '{}');
            $http->expect('PUT', 'https://www.googleapis.com/youtube/v3/videos?part=snippet', 200, '{}');
            $api->setThumbnail('video-1', $path, 'secret');
            $api->updateVideo('video-1', ['title' => 'Updated', 'categoryId' => '22'], 'secret');
            self::assertSame('image/jpeg', $http->requests[0]['options']['headers']['Content-Type']);
            self::assertSame('Updated', $http->requests[1]['options']['json']['snippet']['title']);
            $http->assertAllConsumed();
        } finally {
            unlink($path);
        }
    }

    public function testTikTokDirectPostAndStatusAreDecodedFromApiEnvelope(): void
    {
        $http = new MockHttpClient();
        $api = new TikTokApi($http);
        $http->expect('POST', 'https://open.tiktokapis.com/v2/post/publish/creator_info/query/', 200, '{"data":{"privacy_level_options":["SELF_ONLY"]},"error":{"code":"ok"}}');
        $http->expect('POST', 'https://open.tiktokapis.com/v2/post/publish/video/init/', 200, '{"data":{"publish_id":"pub-1"},"error":{"code":"ok"}}');
        $http->expect('POST', 'https://open.tiktokapis.com/v2/post/publish/status/fetch/', 200, '{"data":{"status":"PUBLISH_COMPLETE"},"error":{"code":"ok"}}');
        self::assertSame(['SELF_ONLY'], $api->creator('secret')['privacy_level_options']);
        self::assertSame('pub-1', $api->startPull(['privacy_level' => 'SELF_ONLY'], 'https://media.example/video.mp4?sig=1', 'secret')['publish_id']);
        self::assertSame('PUBLISH_COMPLETE', $api->status('pub-1', 'secret')['status']);
        self::assertSame('PULL_FROM_URL', $http->requests[1]['options']['json']['source_info']['source']);
        $http->assertAllConsumed();
    }

    public function testTikTokQuotaResponseIsRetryable(): void
    {
        $http = new MockHttpClient();
        $http->expect('POST', 'https://open.tiktokapis.com/v2/post/publish/creator_info/query/', 403, '{"error":{"code":"spam_risk_too_many_posts"}}');
        try {
            (new TikTokApi($http))->creator('secret');
            self::fail('Expected a quota error.');
        } catch (PlatformError $error) {
            self::assertSame(ErrorKind::RateLimited, $error->kind);
        }
    }

    public function testTikTokRejectsMissingDataAndUnavailableStatistics(): void
    {
        $http = new MockHttpClient();
        $api = new TikTokApi($http);
        $http->expect('POST', 'https://open.tiktokapis.com/v2/post/publish/creator_info/query/', 200, '{"error":{"code":"ok"}}');
        try {
            $api->creator('secret');
            self::fail('Expected missing data.');
        } catch (PlatformError $error) {
            self::assertSame(ErrorKind::Temporary, $error->kind);
        }
        $http->expect('POST', 'https://open.tiktokapis.com/v2/video/query/?fields=id,view_count,like_count,comment_count,share_count', 401, '{"error":{"code":"access_token_invalid"}}');
        try {
            $api->videoStats('123', 'secret');
            self::fail('Expected revoked statistics access.');
        } catch (PlatformError $error) {
            self::assertSame(ErrorKind::Auth, $error->kind);
        }
        $http->expect('POST', 'https://open.tiktokapis.com/v2/post/publish/video/init/', 500, '{"error":{"code":"internal_error"}}');
        try {
            $api->startPull(['privacy_level' => 'SELF_ONLY'], 'https://media.example/clip', 'secret');
            self::fail('Expected uncertain direct post.');
        } catch (PlatformError $error) {
            self::assertSame(ErrorKind::UnknownOutcome, $error->kind);
        }
        $http->assertAllConsumed();
    }

    public function testYouTubeReportsProjectQuotaAndInvalidSessionRanges(): void
    {
        $http = new MockHttpClient();
        $api = new YouTubeApi($http);
        $http->expect(
            'GET',
            'https://www.googleapis.com/youtube/v3/videos?part=status,processingDetails,snippet,statistics&id=video-1',
            403,
            '{"error":{"errors":[{"reason":"quotaExceeded"}]}}'
        );
        try {
            $api->video('video-1', 'secret');
            self::fail('Expected quota exhaustion.');
        } catch (PlatformError $error) {
            self::assertSame(ErrorKind::RateLimited, $error->kind);
            self::assertSame(86400, $error->retryAfter);
        }
        $session = 'https://www.googleapis.com/upload/youtube/v3/videos?upload_id=abc';
        $http->expect('PUT', $session, 308, '', ['Range' => 'bytes=5-8']);
        try {
            $api->uploadStatus($session, 10, 'secret');
            self::fail('Expected unsafe offset.');
        } catch (PlatformError $error) {
            self::assertSame(ErrorKind::UnknownOutcome, $error->kind);
        }
        $http->expect(
            'POST',
            'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status',
            500,
            '{"error":{"errors":[{"reason":"backendError"}]}}'
        );
        try {
            $api->startUpload([], 8, 'secret');
            self::fail('Expected uncertain upload start.');
        } catch (PlatformError $error) {
            self::assertSame(ErrorKind::UnknownOutcome, $error->kind);
        }
        $http->assertAllConsumed();
    }
}
