<?php

declare(strict_types=1);

namespace App\Tests\Unit\Channel;

use App\Domain\Channel\Channel;
use App\Domain\Channel\ChannelMode;
use App\Domain\Channel\ChannelStatus;
use App\Integrations\Social\Contracts\Credential;
use App\Integrations\Social\Contracts\Platform;
use App\Integrations\Social\TikTok\TikTokApi;
use App\Integrations\Social\TikTok\TikTokStatsFetcher;
use App\Integrations\Social\YouTube\YouTubeApi;
use App\Integrations\Social\YouTube\YouTubeStatsFetcher;
use App\Tests\Support\MockHttpClient;
use PHPUnit\Framework\TestCase;

final class ShortVideoStatsTest extends TestCase
{
    public function testTikTokCountersRequireAPublicNumericVideoId(): void
    {
        $http = new MockHttpClient();
        $fetcher = new TikTokStatsFetcher(new TikTokApi($http));
        $channel = $this->channel(Platform::TikTok);
        $credential = new Credential(Platform::TikTok, 'fixture');
        self::assertNull($fetcher->post($channel, $credential, 'pending-publish-id')->views);
        $http->expect(
            'POST',
            'https://open.tiktokapis.com/v2/video/query/?fields=id,view_count,like_count,comment_count,share_count',
            200,
            '{"data":{"videos":[{"view_count":120,"like_count":10,"comment_count":2,"share_count":3}]},"error":{"code":"ok"}}'
        );
        $metrics = $fetcher->post($channel, $credential, '12345');
        self::assertSame(120, $metrics->views);
        self::assertSame(15, $metrics->engagement);
        self::assertNull($fetcher->followers($channel, $credential));
        $http->assertAllConsumed();
    }

    public function testYouTubeVideoAndSelectedChannelCounters(): void
    {
        $http = new MockHttpClient();
        $fetcher = new YouTubeStatsFetcher(new YouTubeApi($http));
        $channel = $this->channel(Platform::YouTube);
        $credential = new Credential(Platform::YouTube, 'fixture');
        $http->expect(
            'GET',
            'https://www.googleapis.com/youtube/v3/videos?part=status,processingDetails,snippet,statistics&id=video-1',
            200,
            '{"items":[{"statistics":{"viewCount":"120","likeCount":"10","commentCount":"2"}}]}'
        );
        $metrics = $fetcher->post($channel, $credential, 'video-1');
        self::assertSame(120, $metrics->views);
        self::assertSame(12, $metrics->engagement);
        $http->expect(
            'GET',
            'https://www.googleapis.com/youtube/v3/channels?part=snippet,contentDetails,statistics&mine=true',
            200,
            '{"items":[{"id":"other","statistics":{"subscriberCount":"999"}},{"id":"channel-1","statistics":{"subscriberCount":"25"}}]}'
        );
        self::assertSame(25, $fetcher->followers($channel, $credential));
        $http->assertAllConsumed();
    }

    private function channel(Platform $platform): Channel
    {
        return new Channel(
            1,
            'fixture',
            1,
            $platform,
            'channel-1',
            ChannelMode::Account,
            'Creator',
            null,
            null,
            'channel',
            null,
            ChannelStatus::Active,
            null,
            [],
            null,
            null,
            new \DateTimeImmutable()
        );
    }
}
