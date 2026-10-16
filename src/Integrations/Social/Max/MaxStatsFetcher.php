<?php

declare(strict_types=1);

namespace App\Integrations\Social\Max;

use App\Domain\Analytics\PostMetrics;
use App\Domain\Channel\Channel;
use App\Integrations\Social\Contracts\{Credential,StatsFetcher};

/** MAX exposes optional Message.stat for channels; absence remains unavailable. */
final class MaxStatsFetcher implements StatsFetcher
{
    public function __construct(private readonly MaxClientFactory $clients)
    {
    }
    public function post(Channel $channel, Credential $credential, string $externalId): PostMetrics
    {
        $r = $this->clients->make($credential->secret)->call('GET', '/messages/' . rawurlencode($externalId));
        return new PostMetrics(views: PostMetrics::counter($r['stat']['views'] ?? null), shares: PostMetrics::counter($r['stat']['reposts'] ?? null));
    }
    public function followers(Channel $channel, Credential $credential): ?int
    {
        $r = $this->clients->make($credential->secret)->getChat((int) $channel->externalId);
        return PostMetrics::counter($r['participants_count'] ?? null);
    }
}
