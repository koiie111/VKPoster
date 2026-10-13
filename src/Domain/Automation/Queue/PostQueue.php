<?php

declare(strict_types=1);

namespace App\Domain\Automation\Queue;

/**
 * A queue of posts with a weekly schedule of slots for a group of channels. Posts wait in order; each takes the next free slot.
 */
final class PostQueue
{
    /**
     * @param list<array{weekday: int, time: string}> $slots
     * @param list<int> $channelIds
     */
    public function __construct(
        public readonly int $id,
        public readonly string $publicId,
        public readonly int $workspaceId,
        public readonly string $name,
        public readonly bool $paused,
        public readonly array $slots,
        public readonly array $channelIds,
    ) {
    }
}
