<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Kernel\Queue\AbstractJob;

/** One publication milestone or one channel/day; retries are read-only and idempotent. */
final class StatsJob extends AbstractJob
{
    public const QUEUE = 'analytics';
    public function __construct(public readonly int $taskId)
    {
    }
    public static function fromPayload(array $payload): static
    {
        return new static((int) ($payload['task_id'] ?? 0));
    }
    public function toPayload(): array
    {
        return ['task_id' => $this->taskId];
    }
    public function handle(StatsCollector $collector): void
    {
        $collector->run($this->taskId);
    }
    public function maxAttempts(): int
    {
        return 5;
    }
    public function backoff(int $attempt): int
    {
        return [60,300,900,3600][min(3, max(0, $attempt - 1))];
    }
}
