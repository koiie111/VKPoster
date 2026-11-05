<?php

declare(strict_types=1);

namespace App\Tests\Integration\Post;

use App\Domain\Media\MediaKind;
use App\Domain\Post\PostKind;
use App\Domain\Post\ShortVideoOptions;
use App\Domain\Post\VideoProviderSessions;
use App\Domain\Post\VideoUploadQuota;
use App\Integrations\Social\Contracts\Credential;
use App\Integrations\Social\Contracts\ErrorKind;
use App\Integrations\Social\Contracts\Platform;
use App\Integrations\Social\Contracts\PlatformError;
use App\Integrations\Social\Contracts\PublishMedia;
use App\Integrations\Social\Contracts\PublishRequest;
use App\Integrations\Social\Contracts\PublishResult;
use App\Integrations\Social\TikTok\TikTokAdapter;
use App\Integrations\Social\TikTok\TikTokApi;
use App\Integrations\Social\YouTube\YouTubeAdapter;
use App\Integrations\Social\YouTube\YouTubeApi;
use App\Tests\Support\PostTestCase;

final class ShortVideoAdaptersTest extends PostTestCase
{
    /** @return array{string, string} idempotency key, local path */
    private function publication(): array
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $context = $this->contextFor($workspace, $owner);
        $channel = $this->fakeChannel($workspace, $owner);
        [, $publications] = $this->scheduled($context, [$channel]);
        return [$publications[0]->idempotencyKey, $this->tempFile('12345678')];
    }

    public function testYouTubeResumesFromServerOffsetAndReusesTheAcceptedVideo(): void
    {
        [$key, $path] = $this->publication();
        $adapter = new YouTubeAdapter(new YouTubeApi($this->http), $this->app->container()->get(VideoProviderSessions::class), $this->app->container()->get(VideoUploadQuota::class), 1);
        self::assertSame(Platform::YouTube, $adapter->platform());
        self::assertTrue($adapter->capabilities()->shortVideo);
        $url = 'https://www.googleapis.com/upload/youtube/v3/videos?upload_id=resume';
        $this->http->expect('POST', 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status', 200, '', ['Location' => $url]);
        $this->http->expect('PUT', $url, 308, '', ['Range' => 'bytes=0-3']);
        $this->http->expect('PUT', $url, 201, '{"id":"video-1"}');
        $request = new PublishRequest(
            'Описание',
            [new PublishMedia(MediaKind::Video, $path, 'clip.mp4', 'video/mp4')],
            kind: PostKind::ShortVideo,
            shortVideo: new ShortVideoOptions(title: 'Ролик', privacy: 'private', madeForKids: false)
        );
        $credential = new Credential(Platform::YouTube, 'token');
        $first = $adapter->publish($request, 'channel-1', $credential, $key);
        self::assertTrue($first->processing);
        self::assertSame('video-1', $first->externalId);
        self::assertSame('5678', $this->http->requests[2]['options']['body']);
        self::assertSame($first->externalId, $adapter->publish($request, 'channel-1', $credential, $key)->externalId);
        self::assertCount(3, $this->http->requests, 'retry must not create another YouTube video');
        self::assertSame(0, $this->app->container()->get(VideoUploadQuota::class)->remainingYouTube($this->clock->now(), 1));
        $this->http->assertAllConsumed();
    }

    public function testTikTokUsesCreatorRestrictionsAndPollsAcceptedPublishId(): void
    {
        [$key] = $this->publication();
        $adapter = new TikTokAdapter(new TikTokApi($this->http), $this->app->container()->get(VideoProviderSessions::class), 'https://media.example/', 's3');
        self::assertSame(Platform::TikTok, $adapter->platform());
        self::assertTrue($adapter->capabilities()->shortVideo);
        $this->http->expect(
            'POST',
            'https://open.tiktokapis.com/v2/post/publish/creator_info/query/',
            200,
            '{"data":{"privacy_level_options":["SELF_ONLY"],"comment_disabled":false,"duet_disabled":false,"stitch_disabled":false},"error":{"code":"ok"}}'
        );
        $this->http->expect('POST', 'https://open.tiktokapis.com/v2/post/publish/video/init/', 200, '{"data":{"publish_id":"pub-1"},"error":{"code":"ok"}}');
        $this->http->expect('POST', 'https://open.tiktokapis.com/v2/post/publish/status/fetch/', 200, '{"data":{"status":"PUBLISH_COMPLETE","publicaly_available_post_id":["123"]},"error":{"code":"ok"}}');
        $request = new PublishRequest(
            'Описание',
            [new PublishMedia(MediaKind::Video, '', 'clip.mp4', 'video/mp4', 'https://media.example/clip.mp4?sig=x')],
            kind: PostKind::ShortVideo,
            shortVideo: new ShortVideoOptions(privacy: 'SELF_ONLY', musicRightsAccepted: true)
        );
        $credential = new Credential(Platform::TikTok, 'token');
        self::assertSame('pub-1', $adapter->publish($request, 'creator-1', $credential, $key)->externalId);
        self::assertTrue($adapter->publish($request, 'creator-1', $credential, $key)->processing);
        self::assertCount(2, $this->http->requests, 'retry must not start another TikTok post');
        self::assertSame('123', $adapter->pollVideo('pub-1', 'creator-1', $credential)?->externalId);
        $this->http->assertAllConsumed();
    }

    public function testYouTubeFindsAnUncertainUploadByItsMarkerAndPollsUntilProcessed(): void
    {
        [$key] = $this->publication();
        $sessions = $this->app->container()->get(VideoProviderSessions::class);
        $adapter = new YouTubeAdapter(new YouTubeApi($this->http), $sessions, $this->app->container()->get(VideoUploadQuota::class), 100);
        $request = new PublishRequest(
            'Описание',
            [],
            kind: PostKind::ShortVideo,
            shortVideo: new ShortVideoOptions(title: 'Ролик', privacy: 'private', madeForKids: false)
        );
        $request = $request->withIdempotencyKey($key);
        $credential = new Credential(Platform::YouTube, 'token');
        $marker = 'ezposter-' . substr(hash('sha256', $key), 0, 24);
        $this->http->expect(
            'GET',
            'https://www.googleapis.com/youtube/v3/channels?part=snippet,contentDetails,statistics&mine=true',
            200,
            '{"items":[{"id":"channel-1","contentDetails":{"relatedPlaylists":{"uploads":"playlist-1"}}}]}'
        );
        $this->http->expect(
            'GET',
            'https://www.googleapis.com/youtube/v3/playlistItems?part=contentDetails&maxResults=50&playlistId=playlist-1',
            200,
            '{"items":[{"contentDetails":{"videoId":"video-1"}}]}'
        );
        $this->http->expect(
            'GET',
            'https://www.googleapis.com/youtube/v3/videos?part=snippet&id=video-1',
            200,
            '{"items":[{"id":"video-1","snippet":{"channelId":"channel-1","tags":["' . $marker . '"]}}]}'
        );
        $found = $adapter->findPublished($request, 'channel-1', $credential, $this->clock->now());
        self::assertNotNull($found);
        self::assertSame('video-1', $found->externalId);
        self::assertTrue($found->processing);
        $saved = $sessions->find($key);
        self::assertNotNull($saved);
        self::assertSame('video-1', $saved['remote_id']);
        $this->http->expect(
            'GET',
            'https://www.googleapis.com/youtube/v3/videos?part=status,processingDetails,snippet,statistics&id=video-1',
            200,
            '{"items":[{"status":{"uploadStatus":"uploaded"},"processingDetails":{"processingStatus":"processing"}}]}'
        );
        $pending = $adapter->pollVideo('video-1', 'channel-1', $credential);
        self::assertNull($pending);
        $this->http->expect(
            'GET',
            'https://www.googleapis.com/youtube/v3/videos?part=status,processingDetails,snippet,statistics&id=video-1',
            200,
            '{"items":[{"status":{"uploadStatus":"processed"}}]}'
        );
        $processed = $adapter->pollVideo('video-1', 'channel-1', $credential);
        self::assertNotNull($processed);
        self::assertSame('video-1', $processed->externalId);
        $this->http->assertAllConsumed();
    }

    public function testYouTubeEditThumbnailDeleteAndHealthUseTheAuthorizedChannel(): void
    {
        $adapter = new YouTubeAdapter(new YouTubeApi($this->http), $this->app->container()->get(VideoProviderSessions::class), $this->app->container()->get(VideoUploadQuota::class), 100);
        $credential = new Credential(Platform::YouTube, 'token');
        $published = new PublishResult('video-1', null);
        $request = new PublishRequest(
            'Новое описание',
            [],
            kind: PostKind::ShortVideo,
            shortVideo: new ShortVideoOptions(title: 'Новый заголовок', privacy: 'private', madeForKids: false)
        );
        $this->http->expect('PUT', 'https://www.googleapis.com/youtube/v3/videos?part=snippet', 200, '{}');
        $adapter->edit($published, 'channel-1', $credential, $request, false);
        $cover = $this->tempFile('jpeg-fixture');
        $this->http->expect('POST', 'https://www.googleapis.com/upload/youtube/v3/thumbnails/set?videoId=video-1&uploadType=media', 200, '{}');
        $adapter->setThumbnail('video-1', $cover, $credential);
        $this->http->expect('DELETE', 'https://www.googleapis.com/youtube/v3/videos?id=video-1', 204, '');
        $adapter->delete($published, 'channel-1', $credential);
        $this->http->expect(
            'GET',
            'https://www.googleapis.com/youtube/v3/channels?part=snippet,contentDetails,statistics&mine=true',
            200,
            '{"items":[{"id":"channel-1","snippet":{"title":"Creator"}}]}'
        );
        $health = $adapter->healthCheck('channel-1', $credential);
        self::assertTrue($health->ok);
        self::assertSame('Creator', $health->title);
        try {
            $adapter->pin($published, 'channel-1', $credential, true);
            self::fail('YouTube Shorts cannot be pinned.');
        } catch (PlatformError $error) {
            self::assertSame(ErrorKind::Permanent, $error->kind);
        }
        self::assertSame('Новый заголовок', $this->http->requests[0]['options']['json']['snippet']['title']);
        $this->http->assertAllConsumed();
    }

    public function testTikTokRejectsUnverifiedMediaAndCreatorRestrictions(): void
    {
        [$key] = $this->publication();
        $sessions = $this->app->container()->get(VideoProviderSessions::class);
        $credential = new Credential(Platform::TikTok, 'token');
        $request = new PublishRequest(
            'Описание',
            [new PublishMedia(MediaKind::Video, '', 'clip.mp4', 'video/mp4', 'https://media.example/clip.mp4')],
            kind: PostKind::ShortVideo,
            shortVideo: new ShortVideoOptions(privacy: 'SELF_ONLY', musicRightsAccepted: true, allowDuet: true)
        );
        $local = new TikTokAdapter(new TikTokApi($this->http), $sessions, 'https://media.example/', 'local');
        try {
            $local->publish($request, 'creator-1', $credential, $key);
            self::fail('Expected S3 restriction.');
        } catch (PlatformError $error) {
            self::assertSame(ErrorKind::Permanent, $error->kind);
        }
        $adapter = new TikTokAdapter(new TikTokApi($this->http), $sessions, 'https://media.example/', 's3');
        $this->http->expect(
            'POST',
            'https://open.tiktokapis.com/v2/post/publish/creator_info/query/',
            200,
            '{"data":{"privacy_level_options":["SELF_ONLY"],"duet_disabled":true},"error":{"code":"ok"}}'
        );
        try {
            $adapter->publish($request, 'creator-1', $credential, $key);
            self::fail('Expected creator restriction.');
        } catch (PlatformError $error) {
            self::assertSame(ErrorKind::Permanent, $error->kind);
        }
        self::assertNotEmpty($adapter->validate(new PublishRequest('Описание', [], kind: PostKind::ShortVideo)));
        $this->http->assertAllConsumed();
    }

    public function testTikTokProcessingFailureAndHealthAreReported(): void
    {
        $adapter = new TikTokAdapter(new TikTokApi($this->http), $this->app->container()->get(VideoProviderSessions::class), 'https://media.example/', 's3');
        $credential = new Credential(Platform::TikTok, 'token');
        $this->http->expect(
            'POST',
            'https://open.tiktokapis.com/v2/post/publish/status/fetch/',
            200,
            '{"data":{"status":"PROCESSING_UPLOAD"},"error":{"code":"ok"}}'
        );
        self::assertNull($adapter->pollVideo('pub-1', 'creator-1', $credential));
        $this->http->expect(
            'POST',
            'https://open.tiktokapis.com/v2/post/publish/status/fetch/',
            200,
            '{"data":{"status":"FAILED","fail_reason":"video_format_check_failed"},"error":{"code":"ok"}}'
        );
        try {
            $adapter->pollVideo('pub-1', 'creator-1', $credential);
            self::fail('Expected processing failure.');
        } catch (PlatformError $error) {
            self::assertSame(ErrorKind::Permanent, $error->kind);
        }
        $this->http->expect(
            'POST',
            'https://open.tiktokapis.com/v2/post/publish/creator_info/query/',
            200,
            '{"data":{"privacy_level_options":["SELF_ONLY"]},"error":{"code":"ok"}}'
        );
        self::assertTrue($adapter->healthCheck('creator-1', $credential)->ok);
        $this->http->assertAllConsumed();
    }
}
