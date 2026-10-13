<?php

declare(strict_types=1);

namespace App\Domain\Automation\Import;

/**
 * An uploaded file after checking: the rows (good and bad), what was asked (drafts or a schedule) and, once it ran, what became of it.
 * Statuses: `preview` (waits for the person), `processing` (queued), `running`, `done`.
 */
final class ImportBatch
{
    public const MODE_DRAFT = 'draft';
    public const MODE_SCHEDULE = 'schedule';

    /**
     * @param list<ImportRow> $rows
     * @param list<array{line: int, message: string}> $failed rows that passed the check but could not be created
     */
    public function __construct(
        public readonly int $id,
        public readonly string $publicId,
        public readonly int $workspaceId,
        public readonly int $userId,
        public readonly string $filename,
        public readonly string $mode,
        public readonly string $status,
        public readonly array $rows,
        public readonly int $createdCount,
        public readonly array $failed,
    ) {
    }

    public function okCount(): int
    {
        return count(array_filter($this->rows, static fn (ImportRow $r): bool => $r->ok()));
    }

    public function errorCount(): int
    {
        return count($this->rows) - $this->okCount();
    }
}
