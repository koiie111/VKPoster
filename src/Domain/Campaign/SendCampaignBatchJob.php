<?php

declare(strict_types=1);

namespace App\Domain\Campaign;

use App\Kernel\Queue\AbstractJob;
use App\Kernel\Queue\Queue;

/**
 * Sends the next batch of a campaign and, while people are still waiting, puts the next batch on the queue a minute later: that is how the
 * sending speed is limited. Safe to run twice (a recipient that was sent is not queued any more).
 */
final class SendCampaignBatchJob extends AbstractJob
{
    public function __construct(public readonly int $campaignId)
    {
    }

    public static function fromPayload(array $payload): static
    {
        return new static(is_int($payload['campaign'] ?? null) ? $payload['campaign'] : 0);
    }

    public function toPayload(): array
    {
        return ['campaign' => $this->campaignId];
    }

    public function handle(Campaigns $campaigns, Queue $queue): void
    {
        if ($campaigns->sendBatch($this->campaignId) > 0) {
            $queue->dispatch(new self($this->campaignId), 60);
        }
    }
}
