<?php

declare(strict_types=1);

namespace App\Domain\Automation\Rss;

use DateTimeImmutable;

/**
 * A subscribed feed: where it is, how often it is read, what a post made from an entry looks like, where the posts go (drafts,
 * or the end of a queue), and what the last reading said.
 */
final class RssFeed
{
    public const MODE_DRAFT = 'draft';
    public const MODE_QUEUE = 'queue';
    public const DEFAULT_TEMPLATE = "{title}\n\n{summary}\n\n{link}";

    /**
     * @param list<int> $channelIds
     */
    public function __construct(
        public readonly int $id,
        public readonly string $publicId,
        public readonly int $workspaceId,
        public readonly string $name,
        public readonly string $url,
        public readonly string $template,
        public readonly string $mode,
        public readonly ?int $queueId,
        public readonly int $intervalMinutes,
        public readonly bool $enabled,
        public readonly ?string $etag,
        public readonly ?string $lastModified,
        public readonly ?DateTimeImmutable $lastCheckedAt,
        public readonly ?DateTimeImmutable $nextCheckAt,
        public readonly ?string $lastError,
        public readonly int $errorCount,
        public readonly array $channelIds,
        public readonly ?int $createdBy,
    ) {
    }
}
