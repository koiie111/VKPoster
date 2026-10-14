<?php

declare(strict_types=1);

namespace App\Domain\Automation\Queue;

/**
 * A set of posts that are published round and round into the slots of a queue. A post comes round again only after
 * `minIntervalDays`; the pool keeps `keepAhead` copies waiting in the queue.
 */
final class EvergreenPool
{
    public function __construct(
        public readonly int $id,
        public readonly string $publicId,
        public readonly int $workspaceId,
        public readonly int $queueId,
        public readonly string $name,
        public readonly int $minIntervalDays,
        public readonly int $keepAhead,
        public readonly bool $enabled,
    ) {
    }
}
