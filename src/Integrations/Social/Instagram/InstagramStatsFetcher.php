<?php

declare(strict_types=1);

namespace App\Integrations\Social\Instagram;

use App\Domain\Analytics\PostMetrics;
use App\Domain\Channel\Channel;
use App\Integrations\Social\Contracts\{Credential,StatsFetcher};

/** Instagram Login Insights, independently queried to tolerate unsupported media metrics. */
final class InstagramStatsFetcher implements StatsFetcher
{
    public function __construct(private readonly InstagramApi $api)
    {
    }
    public function post(Channel $channel, Credential $credential, string $externalId): PostMetrics
    {
        $r = $this->api->get('/'.$externalId.'/insights', ['metric' => 'views,reach,total_interactions,likes,comments,shares'], $credential->secret);
        $v = [];
        foreach ($r['data'] ?? [] as $metric) {
            $v[$metric['name']] = PostMetrics::counter($metric['total_value']['value'] ?? $metric['values'][0]['value'] ?? null);
        }
        return new PostMetrics($v['views'] ?? null, $v['reach'] ?? null, $v['total_interactions'] ?? null, $v['likes'] ?? null, $v['comments'] ?? null, $v['shares'] ?? null);
    }
    public function followers(Channel $channel, Credential $credential): ?int
    {
        $r = $this->api->get('/'.$channel->externalId, ['fields' => 'followers_count'], $credential->secret);
        return PostMetrics::counter($r['followers_count'] ?? null);
    }
}
