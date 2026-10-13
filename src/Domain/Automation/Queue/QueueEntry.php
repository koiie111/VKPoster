<?php

declare(strict_types=1);

namespace App\Domain\Automation\Queue;

/**
 * A post waiting in a queue. `note` is why it could not be placed (a channel problem, a plan limit), shown next to it.
 */
final class QueueEntry
{
    public function __construct(
        public readonly int $id,
        public readonly int $queueId,
        public readonly int $postId,
        public readonly int $position,
        public readonly string $source,
        public readonly ?string $note,
    ) {
    }
}
