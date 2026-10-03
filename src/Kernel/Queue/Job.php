<?php

declare(strict_types=1);

namespace App\Kernel\Queue;

/**
 * A unit of background work. Payloads are stored as JSON (`toPayload()`), so jobs carry ids and
 * plain values only, never entities. `handle()` parameters are autowired from the container.
 *
 * Jobs must be idempotent: a crashed worker leaves the job reserved until its visibility timeout
 * expires and then it runs again.
 */
interface Job
{
    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(array $payload): static;

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array;

    /**
     * Retry budget (including the first run).
     */
    public function maxAttempts(): int;

    /**
     * Seconds to wait before retry number `$attempt` (1 = delay after the first failure).
     */
    public function backoff(int $attempt): int;
}
