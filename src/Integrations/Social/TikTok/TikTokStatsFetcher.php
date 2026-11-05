<?php

declare(strict_types=1);

namespace App\Integrations\Social\TikTok;

use App\Domain\Analytics\PostMetrics;
use App\Domain\Channel\Channel;
use App\Integrations\Social\Contracts\Credential;
use App\Integrations\Social\Contracts\StatsFetcher;

/** Public-video counters; TikTok does not expose private post IDs to the posting API. */
final class TikTokStatsFetcher implements StatsFetcher
{
    public function __construct(private readonly TikTokApi $api)
    {
    }

    public function post(Channel $channel, Credential $credential, string $externalId): PostMetrics
    {
        if (preg_match('/^\d{1,32}$/D', $externalId) !== 1) {
            return new PostMetrics(statuses: array_fill_keys(['views', 'reach', 'engagement', 'likes', 'comments', 'shares'], 'unavailable'));
        }
        $data = $this->api->videoStats($externalId, $credential->secret);
        $videos = is_array($data['videos'] ?? null) ? $data['videos'] : [];
        $video = is_array($videos[0] ?? null) ? $videos[0] : [];
        $views = PostMetrics::counter($video['view_count'] ?? null);
        $likes = PostMetrics::counter($video['like_count'] ?? null);
        $comments = PostMetrics::counter($video['comment_count'] ?? null);
        $shares = PostMetrics::counter($video['share_count'] ?? null);
        return new PostMetrics(
            $views,
            null,
            $likes !== null && $comments !== null && $shares !== null ? $likes + $comments + $shares : null,
            $likes,
            $comments,
            $shares
        );
    }

    public function followers(Channel $channel, Credential $credential): ?int
    {
        return null;
    }
}
