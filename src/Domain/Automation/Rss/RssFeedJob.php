<?php

declare(strict_types=1);

namespace App\Domain\Automation\Rss;

use App\Kernel\Queue\AbstractJob;

/**
 * Reads one feed. Safe to run twice: entries are claimed one by one, so a feed read twice makes no second post.
 */
final class RssFeedJob extends AbstractJob
{
    public function __construct(public readonly int $feedId)
    {
    }

    public static function fromPayload(array $payload): static
    {
        return new static(is_int($payload['feed_id'] ?? null) ? $payload['feed_id'] : 0);
    }

    public function toPayload(): array
    {
        return ['feed_id' => $this->feedId];
    }

    public function maxAttempts(): int
    {
        return 1; // a failed reading is recorded on the feed and tried again at its next time; the job itself is not retried
    }

    public function handle(RssPoller $poller): void
    {
        $poller->poll($this->feedId);
    }
}
