<?php

declare(strict_types=1);

namespace App\Domain\Automation\Import;

use App\Domain\Workspace\WorkspaceContext;
use App\Domain\Workspace\WorkspaceScopedRepository;
use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;
use Symfony\Component\Uid\Ulid;

/**
 * Checked import files of a workspace, kept until they ran (and a month after, for the report).
 */
final class ImportRepository extends WorkspaceScopedRepository
{
    public function __construct(Connection $db, private readonly Clock $clock)
    {
        parent::__construct($db);
    }

    public function find(WorkspaceContext $context, string $publicId): ?ImportBatch
    {
        $row = $this->scoped($context, 'import_batches')->where('public_id', '=', strtoupper($publicId))->first();

        return $row === null ? null : self::hydrate($row);
    }

    /**
     * @return list<ImportBatch> newest first, without the rows' detail being needed by the caller
     */
    public function recent(WorkspaceContext $context, int $limit = 10): array
    {
        return array_map(self::hydrate(...), $this->scoped($context, 'import_batches')->orderBy('id', 'desc')->limit($limit)->get());
    }

    /**
     * @param list<ImportRow> $rows
     */
    public function create(WorkspaceContext $context, string $filename, string $mode, array $rows): ImportBatch
    {
        $now = DbTime::format($this->clock->now());
        $publicId = (string) new Ulid();
        $ok = count(array_filter($rows, static fn (ImportRow $r): bool => $r->ok()));
        $this->db->table('import_batches')->insert([
            'public_id' => $publicId, 'workspace_id' => $context->workspaceId, 'user_id' => $context->userId, 'filename' => mb_substr($filename, 0, 255),
            'mode' => $mode, 'status' => 'preview', 'rows_json' => json_encode(array_map(static fn (ImportRow $r): array => $r->toArray(), $rows), JSON_THROW_ON_ERROR),
            'total' => count($rows), 'ok_count' => $ok, 'error_count' => count($rows) - $ok, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $this->find($context, $publicId) ?? throw new \LogicException('The inserted batch disappeared.');
    }

    /**
     * Move a batch from one status to another; false when it was not in `$from` (somebody pressed twice).
     */
    public function transition(WorkspaceContext $context, ImportBatch $batch, string $from, string $to): bool
    {
        return $this->scoped($context, 'import_batches')->where('id', '=', $batch->id)->where('status', '=', $from)->update(['status' => $to, 'updated_at' => DbTime::format($this->clock->now())]) === 1;
    }

    /**
     * @param list<array{line: int, message: string}> $failed
     */
    public function finish(WorkspaceContext $context, ImportBatch $batch, int $created, array $failed): void
    {
        $this->scoped($context, 'import_batches')->where('id', '=', $batch->id)->update([
            'status' => 'done', 'created_count' => $created, 'failed_json' => json_encode($failed, JSON_THROW_ON_ERROR), 'updated_at' => DbTime::format($this->clock->now()),
        ]);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function hydrate(array $row): ImportBatch
    {
        $rows = json_decode((string) $row['rows_json'], true);
        $failed = json_decode((string) ($row['failed_json'] ?? '[]'), true);

        return new ImportBatch(
            (int) $row['id'],
            (string) $row['public_id'],
            (int) $row['workspace_id'],
            (int) $row['user_id'],
            (string) $row['filename'],
            (string) $row['mode'],
            (string) $row['status'],
            array_map(static fn (mixed $r): ImportRow => ImportRow::fromArray(is_array($r) ? $r : []), is_array($rows) ? $rows : []),
            (int) $row['created_count'],
            array_values(array_filter(is_array($failed) ? $failed : [], static fn (mixed $f): bool => is_array($f) && is_int($f['line'] ?? null) && is_string($f['message'] ?? null))),
        );
    }
}
