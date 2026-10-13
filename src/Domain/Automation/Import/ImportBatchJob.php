<?php

declare(strict_types=1);

namespace App\Domain\Automation\Import;

use App\Kernel\Queue\AbstractJob;

/**
 * Creates the posts of a confirmed import. Not retried: the batch moves from `processing` to `running` once, so a second run does nothing.
 */
final class ImportBatchJob extends AbstractJob
{
    public function __construct(public readonly int $batchId)
    {
    }

    public static function fromPayload(array $payload): static
    {
        return new static(is_int($payload['batch_id'] ?? null) ? $payload['batch_id'] : 0);
    }

    public function toPayload(): array
    {
        return ['batch_id' => $this->batchId];
    }

    public function maxAttempts(): int
    {
        return 1;
    }

    public function handle(ImportService $service): void
    {
        $service->run($this->batchId);
    }
}
