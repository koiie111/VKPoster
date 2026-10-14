<?php

declare(strict_types=1);

namespace App\Domain\Automation\Crosspost;

use DateTimeImmutable;

/**
 * A cross-posting rule: posts of one Telegram channel go to other channels, after the filters and with the transformations.
 * Mode `draft` saves them as drafts for a person to look at, `immediate` plans them after `delayMinutes`.
 */
final class CrosspostRule
{
    public const MODE_DRAFT = 'draft';
    public const MODE_IMMEDIATE = 'immediate';
    public const MAX_DELAY_MINUTES = 7 * 24 * 60;

    /**
     * @param list<int> $targetIds channel ids
     */
    public function __construct(
        public readonly int $id,
        public readonly string $publicId,
        public readonly int $workspaceId,
        public readonly int $sourceChannelId,
        public readonly string $name,
        public readonly bool $enabled,
        public readonly CrosspostFilter $filter,
        public readonly CrosspostTransform $transform,
        public readonly int $delayMinutes,
        public readonly string $mode,
        public readonly bool $syncEdits,
        public readonly array $targetIds,
        public readonly ?DateTimeImmutable $lastRunAt,
        public readonly ?string $lastNote,
        public readonly int $postsCreated,
        public readonly ?int $createdBy,
    ) {
    }
}
