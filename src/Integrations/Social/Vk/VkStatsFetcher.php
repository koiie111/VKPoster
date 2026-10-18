<?php

declare(strict_types=1);

namespace App\Integrations\Social\Vk;

use App\Domain\Analytics\PostMetrics;
use App\Domain\Channel\Channel;
use App\Integrations\Social\Contracts\{Credential,StatsFetcher,PlatformError,ErrorKind};

/** VK wall counters plus optional reach with permission-aware degradation. */
final class VkStatsFetcher implements StatsFetcher
{
    public function __construct(private readonly VkApi $api)
    {
    }
    public function post(Channel $channel, Credential $credential, string $externalId): PostMetrics
    {
        $response = $this->api->call('wall.getById', ['posts' => '-' . $channel->externalId . '_' . $externalId], $credential->secret);
        $post = $response['items'][0] ?? $response[0] ?? [];
        $likes = PostMetrics::counter($post['likes']['count'] ?? null);
        $comments = PostMetrics::counter($post['comments']['count'] ?? null);
        $shares = PostMetrics::counter($post['reposts']['count'] ?? null);
        $reach = null;
        try {
            $r = $this->api->call('stats.getPostReach', ['owner_id' => '-' . $channel->externalId,'post_ids' => $externalId], $credential->secret);
            $reach = PostMetrics::counter($r[0]['reach_total'] ?? null);
        } catch (PlatformError $e) {
            if (in_array($e->kind, [ErrorKind::Temporary,ErrorKind::RateLimited], true)) {
                throw $e;
            }
        }
        return new PostMetrics(
            PostMetrics::counter($post['views']['count'] ?? null),
            $reach,
            $likes !== null && $comments !== null && $shares !== null ? $likes + $comments + $shares : null,
            $likes,
            $comments,
            $shares
        );
    }
    public function followers(Channel $channel, Credential $credential): ?int
    {
        $r = $this->api->call('groups.getById', ['group_ids' => $channel->externalId,'fields' => 'members_count'], $credential->secret);
        return PostMetrics::counter($r['groups'][0]['members_count'] ?? $r[0]['members_count'] ?? null);
    }
}
