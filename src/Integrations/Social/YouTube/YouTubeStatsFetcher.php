<?php

declare(strict_types=1);

namespace App\Integrations\Social\YouTube;

use App\Domain\Analytics\PostMetrics;
use App\Domain\Channel\Channel;
use App\Integrations\Social\Contracts\Credential;
use App\Integrations\Social\Contracts\StatsFetcher;

/** Low-frequency YouTube video and channel counters; absent counters remain unavailable. */
final class YouTubeStatsFetcher implements StatsFetcher
{
    public function __construct(private readonly YouTubeApi $api)
    {
    }

    public function post(Channel $channel, Credential $credential, string $externalId): PostMetrics
    {
        $data = $this->api->video($externalId, $credential->secret);
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        $video = is_array($items[0] ?? null) ? $items[0] : [];
        $stats = is_array($video['statistics'] ?? null) ? $video['statistics'] : [];
        $views = $this->counter($stats['viewCount'] ?? null);
        $likes = $this->counter($stats['likeCount'] ?? null);
        $comments = $this->counter($stats['commentCount'] ?? null);
        return new PostMetrics(
            $views,
            null,
            $likes !== null && $comments !== null ? $likes + $comments : null,
            $likes,
            $comments,
            null
        );
    }

    public function followers(Channel $channel, Credential $credential): ?int
    {
        $data = $this->api->channels($credential->secret);
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        foreach ($items as $item) {
            if (is_array($item) && ($item['id'] ?? null) === $channel->externalId) {
                $statistics = is_array($item['statistics'] ?? null) ? $item['statistics'] : [];
                return $this->counter($statistics['subscriberCount'] ?? null);
            }
        }
        return null;
    }

    private function counter(mixed $value): ?int
    {
        return is_string($value) && ctype_digit($value) && strlen($value) < 19 ? (int) $value : PostMetrics::counter($value);
    }
}
