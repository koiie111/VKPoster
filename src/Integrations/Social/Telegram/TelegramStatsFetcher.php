<?php

declare(strict_types=1);

namespace App\Integrations\Social\Telegram;

use App\Domain\Analytics\PostMetrics;
use App\Domain\Channel\Channel;
use App\Integrations\Social\Contracts\{Credential,StatsFetcher};

/** Bot API has no read endpoint for post views or reaction history. */
final class TelegramStatsFetcher implements StatsFetcher
{
    public function __construct(private readonly TelegramClientFactory $clients, private readonly \App\Domain\Analytics\TelegramReactions $reactions)
    {
    }
    public function post(Channel $channel, Credential $credential, string $externalId): PostMetrics
    {
        return new PostMetrics(engagement: $this->reactions->count($channel->workspaceId, $channel->id, $externalId));
    }
    public function followers(Channel $channel, Credential $credential): ?int
    {
        $r = $this->clients->make($credential->secret)->call('getChatMemberCount', ['chat_id' => $channel->externalId]);
        return PostMetrics::counter($r['value'] ?? null);
    }
}
