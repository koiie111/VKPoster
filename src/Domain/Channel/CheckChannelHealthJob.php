<?php

declare(strict_types=1);

namespace App\Domain\Channel;

use App\Kernel\Queue\AbstractJob;

/**
 * Background health check of one channel (queued by `ChannelHealthService::enqueueDue()`, one job per channel so a slow
 * platform delays only its own checks). Safe to run twice.
 */
final class CheckChannelHealthJob extends AbstractJob
{
    public function __construct(public readonly int $channelId)
    {
    }

    public static function fromPayload(array $payload): static
    {
        return new static(is_int($payload['channel_id'] ?? null) ? $payload['channel_id'] : 0);
    }

    public function toPayload(): array
    {
        return ['channel_id' => $this->channelId];
    }

    public function handle(ChannelHealthService $health, ChannelSystem $channels): void
    {
        $channel = $channels->find($this->channelId);
        if ($channel !== null) {
            $health->check($channel);
        }
    }
}
