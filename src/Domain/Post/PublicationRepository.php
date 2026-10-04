<?php

declare(strict_types=1);

namespace App\Domain\Post;

use App\Domain\Workspace\WorkspaceContext;
use App\Domain\Workspace\WorkspaceScopedRepository;
use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;
use DateTimeImmutable;
use Symfony\Component\Uid\Ulid;

/**
 * Publications of one workspace, as pages and the post service see them: listing, creating the queued plan, moving a queued
 * publication in time, cancelling it, and reading its journal. The worker's moves (sending, sent, failed) are in `PublicationSystem`.
 */
final class PublicationRepository extends WorkspaceScopedRepository
{
    public function __construct(Connection $db, private readonly Clock $clock)
    {
        parent::__construct($db);
    }

    /**
     * @return list<Publication> oldest first
     */
    public function forPost(WorkspaceContext $context, Post $post): array
    {
        return array_map(self::hydrate(...), $this->scoped($context, 'publications')->where('post_id', '=', $post->id)->orderBy('id')->get());
    }

    public function find(WorkspaceContext $context, string $publicId): ?Publication
    {
        $row = $this->scoped($context, 'publications')->where('public_id', '=', strtoupper($publicId))->first();

        return $row === null ? null : self::hydrate($row);
    }

    /**
     * The newest publication of a variant, whatever its state.
     */
    public function latestFor(WorkspaceContext $context, PostVariant $variant): ?Publication
    {
        $row = $this->scoped($context, 'publications')->where('variant_id', '=', $variant->id)->orderBy('id', 'desc')->first();

        return $row === null ? null : self::hydrate($row);
    }

    public function createQueued(WorkspaceContext $context, Post $post, PostVariant $variant, DateTimeImmutable $dueAt): Publication
    {
        $now = DbTime::format($this->clock->now());
        $publicId = (string) new Ulid();
        $this->db->table('publications')->insert([
            'public_id' => $publicId,
            'workspace_id' => $context->workspaceId,
            'post_id' => $post->id,
            'variant_id' => $variant->id,
            'channel_id' => $variant->channelId,
            'status' => PublicationStatus::Queued->value,
            'attempt' => 0,
            'due_at' => DbTime::format($dueAt),
            'run_at' => DbTime::format($dueAt),
            'idempotency_key' => hash('sha256', $post->publicId . '|' . $variant->id . '|' . $publicId),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->find($context, $publicId) ?? throw new \LogicException('The inserted publication disappeared.');
    }

    /**
     * Move a queued publication to a new time. Returns false when it is not queued any more (a worker took it).
     */
    public function reschedule(WorkspaceContext $context, Publication $publication, DateTimeImmutable $dueAt): bool
    {
        return $this->scoped($context, 'publications')->where('id', '=', $publication->id)->where('status', '=', PublicationStatus::Queued->value)->update([
            'due_at' => DbTime::format($dueAt),
            'run_at' => DbTime::format($dueAt),
            'enqueued_at' => null,
            'updated_at' => DbTime::format($this->clock->now()),
        ]) > 0;
    }

    /**
     * Cancel a queued publication. Returns false when it is not queued any more.
     */
    public function cancel(WorkspaceContext $context, Publication $publication, string $reason = ''): bool
    {
        return $this->scoped($context, 'publications')->where('id', '=', $publication->id)->where('status', '=', PublicationStatus::Queued->value)->update([
            'status' => PublicationStatus::Cancelled->value,
            'error_message' => $reason === '' ? null : mb_substr($reason, 0, 500),
            'updated_at' => DbTime::format($this->clock->now()),
        ]) > 0;
    }

    /**
     * @return list<array{attempt: int, outcome: string, error_kind: ?string, message: ?string, detail: ?string, started_at: ?DateTimeImmutable, finished_at: ?DateTimeImmutable}>
     */
    public function attempts(WorkspaceContext $context, Publication $publication): array
    {
        $rows = $this->scoped($context, 'publication_attempts')->where('publication_id', '=', $publication->id)->orderBy('id')->get();

        return array_map(static fn (array $row): array => [
            'attempt' => (int) $row['attempt'],
            'outcome' => (string) $row['outcome'],
            'error_kind' => is_string($row['error_kind'] ?? null) ? $row['error_kind'] : null,
            'message' => is_string($row['message'] ?? null) ? $row['message'] : null,
            'detail' => is_string($row['detail'] ?? null) ? $row['detail'] : null,
            'started_at' => DbTime::parse($row['started_at'] ?? null),
            'finished_at' => DbTime::parse($row['finished_at'] ?? null),
        ], $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function hydrate(array $row): Publication
    {
        $ids = is_string($row['external_ids_json'] ?? null) ? json_decode($row['external_ids_json'], true) : [];
        $string = static fn (string $key): ?string => is_string($row[$key] ?? null) ? $row[$key] : null;

        return new Publication(
            (int) $row['id'],
            (string) $row['public_id'],
            (int) $row['workspace_id'],
            (int) $row['post_id'],
            (int) $row['variant_id'],
            isset($row['channel_id']) ? (int) $row['channel_id'] : null,
            PublicationStatus::from((string) $row['status']),
            (int) $row['attempt'],
            DbTime::parse($row['due_at']) ?? new DateTimeImmutable('@0'),
            DbTime::parse($row['run_at']) ?? new DateTimeImmutable('@0'),
            DbTime::parse($row['enqueued_at'] ?? null),
            (string) $row['idempotency_key'],
            $string('external_post_id'),
            is_array($ids) ? array_values(array_map('strval', $ids)) : [],
            $string('external_url'),
            $string('error_code'),
            $string('error_message'),
            $string('error_detail'),
            DbTime::parse($row['started_at'] ?? null),
            DbTime::parse($row['sent_at'] ?? null),
            DbTime::parse($row['delete_at'] ?? null),
            DbTime::parse($row['deleted_at'] ?? null),
            $string('delete_error'),
            (int) ($row['pinned'] ?? 0) === 1,
        );
    }
}
