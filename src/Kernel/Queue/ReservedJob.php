<?php

declare(strict_types=1);

namespace App\Kernel\Queue;

/**
 * A job row taken by a worker (reserved, attempt counter already incremented).
 */
final class ReservedJob
{
    /**
     * @param array<string, mixed> $payload decoded `payload_json` (`class` + `data`)
     */
    public function __construct(
        public readonly int $id,
        public readonly string $queue,
        public readonly array $payload,
        public readonly int $attempts,
        public readonly int $maxAttempts,
    ) {
    }
}
