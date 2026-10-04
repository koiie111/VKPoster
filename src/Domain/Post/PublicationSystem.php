<?php

declare(strict_types=1);

namespace App\Domain\Post;

use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;
use DateTimeImmutable;
use LogicException;

/**
 * The publication pipeline's access to data, across workspaces (no member is signed in when a worker runs): claiming a
 * publication, recording the outcome, finding what is due, and keeping the post status in step. Every status change goes through
 * `move()`, which refuses what `PublicationStatus` forbids and only changes the row if it is still in the expected state, so two
 * workers (or a worker and a person) can never both win.
 */
final class PublicationSystem
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    public function find(int $id): ?Publication
    {
        $row = $this->db->table('publications')->where('id', '=', $id)->first();

        return $row === null ? null : PublicationRepository::hydrate($row);
    }

    /**
     * Take a queued publication for sending: it must be due (with `$slackSeconds` of tolerance for clock jitter between the
     * scheduler and the worker). Returns it as it is after the claim, or null when somebody else got there first, it was cancelled,
     * or it is not due yet. Exactly one caller can ever get a non-null answer for one attempt.
     */
    public function claim(int $id, int $slackSeconds = 2): ?Publication
    {
        $now = $this->clock->now();
        $changed = $this->db->execute(
            'UPDATE publications SET status = ?, attempt = attempt + 1, started_at = ?, enqueued_at = NULL, error_code = NULL, updated_at = ?
             WHERE id = ? AND status = ? AND run_at <= ?',
            [PublicationStatus::Sending->value, DbTime::format($now), DbTime::format($now), $id, PublicationStatus::Queued->value, DbTime::format($now->modify(sprintf('+%d seconds', $slackSeconds)))],
        );

        return $changed === 1 ? $this->find($id) : null;
    }

    /**
     * Change the status if (and only if) the publication is still in `$from` and the move is allowed.
     *
     * @param array<string, scalar|null> $changes further columns to set in the same statement
     * @throws LogicException for a move the state machine does not know
     */
    public function move(int $id, PublicationStatus $from, PublicationStatus $to, array $changes = []): bool
    {
        if (!$from->canMoveTo($to)) {
            throw new LogicException(sprintf('A publication cannot go from "%s" to "%s".', $from->value, $to->value));
        }
        $changes['status'] = $to->value;
        $changes['updated_at'] = DbTime::format($this->clock->now());
        $sets = implode(', ', array_map(static fn (string $c): string => $c . ' = ?', array_keys($changes)));
        foreach (array_keys($changes) as $column) {
            if (preg_match('/^[a-z_]+$/', $column) !== 1) {
                throw new LogicException('Bad column name.');
            }
        }

        return $this->db->execute('UPDATE publications SET ' . $sets . ' WHERE id = ? AND status = ?', [...array_values($changes), $id, $from->value]) === 1;
    }

    /**
     * @param list<string> $allIds
     */
    public function markSent(Publication $publication, string $externalId, ?string $url, array $allIds, ?DateTimeImmutable $deleteAt): bool
    {
        return $this->move($publication->id, PublicationStatus::Sending, PublicationStatus::Sent, [
            'external_post_id' => $externalId,
            'external_ids_json' => json_encode($allIds === [] ? [$externalId] : $allIds, JSON_THROW_ON_ERROR),
            'external_url' => $url,
            'sent_at' => DbTime::format($this->clock->now()),
            'delete_at' => $deleteAt === null ? null : DbTime::format($deleteAt),
            'error_code' => null,
            'error_message' => null,
            'error_detail' => null,
        ]);
    }

    public function markFailed(Publication $publication, string $code, string $message, string $detail): bool
    {
        return $this->move($publication->id, PublicationStatus::Sending, PublicationStatus::Failed, $this->errorColumns($code, $message, $detail));
    }

    public function markUnknown(Publication $publication, string $code, string $message, string $detail, PublicationStatus $from = PublicationStatus::Sending): bool
    {
        return $this->move($publication->id, $from, PublicationStatus::Unknown, $this->errorColumns($code, $message, $detail));
    }

    /**
     * Put a publication back in the queue for another attempt after a temporary failure.
     */
    public function requeue(Publication $publication, DateTimeImmutable $runAt, string $code, string $message, string $detail): bool
    {
        return $this->move($publication->id, PublicationStatus::Sending, PublicationStatus::Queued, [
            'run_at' => DbTime::format($runAt),
            'enqueued_at' => null,
        ] + $this->errorColumns($code, $message, $detail));
    }

    public function markPinned(Publication $publication): void
    {
        $this->db->table('publications')->where('id', '=', $publication->id)->update(['pinned' => 1]);
    }

    /**
     * @param list<int> $ids
     */
    public function markEnqueued(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $this->db->table('publications')->whereIn('id', $ids)->update(['enqueued_at' => DbTime::format($this->clock->now())]);
    }

    /**
     * Queued publications that need a job: nothing is queued for them yet and they are due within `$horizonSeconds`, or their job
     * went missing (the publication is still queued `$staleSeconds` after its job was created or it fell due, whichever is later).
     *
     * @return list<array{id: int, run_at: DateTimeImmutable}>
     */
    public function dueForEnqueue(int $horizonSeconds, int $staleSeconds, int $limit): array
    {
        $now = $this->clock->now();
        $rows = $this->db->select(
            'SELECT id, run_at FROM publications
             WHERE status = ? AND run_at <= ? AND (enqueued_at IS NULL OR GREATEST(enqueued_at, run_at) < ?)
             ORDER BY run_at, id LIMIT ' . max(1, $limit),
            [PublicationStatus::Queued->value, DbTime::format($now->modify(sprintf('+%d seconds', $horizonSeconds))), DbTime::format($now->modify(sprintf('-%d seconds', $staleSeconds)))],
        );

        return array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'run_at' => DbTime::parse($row['run_at']) ?? new DateTimeImmutable('@0')], $rows);
    }

    /**
     * Publications that have been "sending" for too long: the worker died. Nobody knows whether the post went out, so they become
     * `unknown` (never retried by themselves: a duplicate is worse than a missing post).
     *
     * @return list<int>
     */
    public function stuckSending(int $olderThanSeconds, int $limit = 200): array
    {
        $rows = $this->db->select(
            'SELECT id FROM publications WHERE status = ? AND started_at < ? ORDER BY id LIMIT ' . max(1, $limit),
            [PublicationStatus::Sending->value, DbTime::format($this->clock->now()->modify(sprintf('-%d seconds', $olderThanSeconds)))],
        );

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /**
     * @return list<int> sent publications whose time to be deleted has come and that have no deletion queued
     */
    public function dueForDeletion(int $staleSeconds, int $limit): array
    {
        $now = $this->clock->now();
        $rows = $this->db->select(
            'SELECT id FROM publications WHERE status = ? AND delete_at IS NOT NULL AND delete_at <= ? AND deleted_at IS NULL
             AND (delete_enqueued_at IS NULL OR delete_enqueued_at < ?) ORDER BY delete_at, id LIMIT ' . max(1, $limit),
            [PublicationStatus::Sent->value, DbTime::format($now), DbTime::format($now->modify(sprintf('-%d seconds', $staleSeconds)))],
        );

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /**
     * @param list<int> $ids
     */
    public function markDeletionEnqueued(array $ids): void
    {
        if ($ids !== []) {
            $this->db->table('publications')->whereIn('id', $ids)->update(['delete_enqueued_at' => DbTime::format($this->clock->now())]);
        }
    }

    public function markDeleted(int $id): void
    {
        $this->db->table('publications')->where('id', '=', $id)->update(['deleted_at' => DbTime::format($this->clock->now()), 'delete_error' => null]);
    }

    /**
     * Give up deleting (the platform refuses): remember why, stop trying.
     */
    public function markDeleteFailed(int $id, string $message): void
    {
        $this->db->table('publications')->where('id', '=', $id)->update(['delete_at' => null, 'delete_error' => mb_substr($message, 0, 500)]);
    }

    public function recordAttempt(Publication $publication, string $outcome, ?string $errorKind, ?string $message, ?string $detail, DateTimeImmutable $startedAt): void
    {
        $this->db->table('publication_attempts')->insert([
            'publication_id' => $publication->id,
            'workspace_id' => $publication->workspaceId,
            'attempt' => max(1, $publication->attempt),
            'outcome' => $outcome,
            'error_kind' => $errorKind,
            'message' => $message === null ? null : mb_substr($message, 0, 500),
            'detail' => $detail === null ? null : mb_substr($detail, 0, 4000),
            'started_at' => DbTime::format($startedAt),
            'finished_at' => DbTime::format($this->clock->now()),
        ]);
    }

    /**
     * The post, the variant and the channel behind a publication, as rows for the pipeline.
     *
     * @return array{post: Post, variant: PostVariant}|null
     */
    public function load(Publication $publication): ?array
    {
        $post = $this->db->table('posts')->where('id', '=', $publication->postId)->first();
        $variant = $this->db->table('post_variants')->where('id', '=', $publication->variantId)->first();
        if ($post === null || $variant === null) {
            return null;
        }

        return ['post' => PostRepository::hydrate($post), 'variant' => PostRepository::hydrateVariant($variant)];
    }

    /**
     * Recompute the status of a post from its publications and store it. The post row is locked meanwhile, so two publications
     * of one post finishing at the same moment cannot overwrite each other's verdict.
     */
    public function syncPostStatus(int $postId): ?PostStatus
    {
        return $this->db->transaction(function (Connection $db) use ($postId): ?PostStatus {
            $row = $db->table('posts')->where('id', '=', $postId)->forUpdate()->first();
            if ($row === null) {
                return null;
            }
            $post = PostRepository::hydrate($row);
            $publications = array_map(PublicationRepository::hydrate(...), $db->table('publications')->where('post_id', '=', $postId)->orderBy('id')->get());
            $status = PostStatusAggregator::aggregate($post->status, $publications);
            if ($post->status === PostStatus::Draft && $publications === []) {
                return PostStatus::Draft;
            }
            $changes = ['status' => $status->value, 'updated_at' => DbTime::format($this->clock->now())];
            if ($status === PostStatus::Published && $post->publishedAt === null) {
                $changes['published_at'] = DbTime::format($this->clock->now());
            }
            if ($status !== $post->status || isset($changes['published_at'])) {
                $db->table('posts')->where('id', '=', $postId)->update($changes);
            }

            return $status;
        });
    }

    /**
     * A channel is being disconnected: its queued publications can never go out, so they are cancelled (with the reason in the
     * journal) and the posts' statuses follow.
     *
     * @return int number of cancelled publications
     */
    public function cancelForChannel(int $channelId, string $reason): int
    {
        $rows = $this->db->table('publications')->where('channel_id', '=', $channelId)->whereIn('status', [PublicationStatus::Queued->value, PublicationStatus::Unknown->value])->get();
        $cancelled = 0;
        $posts = [];
        foreach ($rows as $row) {
            $publication = PublicationRepository::hydrate($row);
            if ($this->move($publication->id, $publication->status, PublicationStatus::Cancelled, ['error_message' => mb_substr($reason, 0, 500)])) {
                ++$cancelled;
                $posts[$publication->postId] = true;
                $this->recordAttempt($publication, 'cancelled', null, $reason, null, $this->clock->now());
            }
        }
        foreach (array_keys($posts) as $postId) {
            $this->syncPostStatus($postId);
        }

        return $cancelled;
    }

    /**
     * @return array<string, scalar|null>
     */
    private function errorColumns(string $code, string $message, string $detail): array
    {
        return ['error_code' => mb_substr($code, 0, 32), 'error_message' => mb_substr($message, 0, 500), 'error_detail' => mb_substr($detail, 0, 4000)];
    }
}
