<?php

declare(strict_types=1);

namespace App\Domain\Automation\Recurring;

use DateTimeImmutable;

/**
 * A stored recurring rule: the post that is repeated (the "master", it stays as it is), the rule, how many copies were made and
 * when the next one is due.
 */
final class RecurringPost
{
    public function __construct(
        public readonly int $id,
        public readonly string $publicId,
        public readonly int $workspaceId,
        public readonly int $postId,
        public readonly RecurrenceRule $rule,
        public readonly int $doneCount,
        public readonly ?DateTimeImmutable $nextRunAt,
        public readonly bool $enabled,
        public readonly ?string $lastError,
    ) {
    }
}
