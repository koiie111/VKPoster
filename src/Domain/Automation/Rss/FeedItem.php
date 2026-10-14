<?php

declare(strict_types=1);

namespace App\Domain\Automation\Rss;

/**
 * One entry of an RSS or Atom feed, reduced to what a post is built from.
 */
final class FeedItem
{
    public function __construct(
        public readonly string $guid,
        public readonly string $title,
        public readonly string $link,
        public readonly string $summary,
        public readonly ?string $imageUrl,
    ) {
    }

    /**
     * Stable key for duplicate detection: the same guid is the same entry, whatever else changed.
     */
    public function key(): string
    {
        return hash('sha256', $this->guid);
    }
}
