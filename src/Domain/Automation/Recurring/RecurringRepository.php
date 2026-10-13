<?php

declare(strict_types=1);

namespace App\Domain\Automation\Recurring;

use App\Domain\Workspace\WorkspaceContext;
use App\Domain\Workspace\WorkspaceScopedRepository;
use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;
use DateTimeImmutable;
use Symfony\Component\Uid\Ulid;

/**
 * Recurring rules of a workspace.
 */
final class RecurringRepository extends WorkspaceScopedRepository
{
    public function __construct(Connection $db, private readonly Clock $clock)
    {
        parent::__construct($db);
    }

    /**
     * @return list<RecurringPost> newest first
     */
    public function all(WorkspaceContext $context): array
    {
        return array_map(self::hydrate(...), $this->scoped($context, 'recurring_rules')->orderBy('id', 'desc')->limit(200)->get());
    }

    public function find(WorkspaceContext $context, string $publicId): ?RecurringPost
    {
        $row = $this->scoped($context, 'recurring_rules')->where('public_id', '=', strtoupper($publicId))->first();

        return $row === null ? null : self::hydrate($row);
    }

    public function findById(WorkspaceContext $context, int $id): ?RecurringPost
    {
        $row = $this->scoped($context, 'recurring_rules')->where('id', '=', $id)->first();

        return $row === null ? null : self::hydrate($row);
    }

    public function count(WorkspaceContext $context): int
    {
        return $this->scoped($context, 'recurring_rules')->count();
    }

    public function create(WorkspaceContext $context, int $postId, RecurrenceRule $rule, ?DateTimeImmutable $nextRunAt): RecurringPost
    {
        $now = DbTime::format($this->clock->now());
        $publicId = (string) new Ulid();
        $this->db->table('recurring_rules')->insert([
            'public_id' => $publicId, 'workspace_id' => $context->workspaceId, 'post_id' => $postId,
            'frequency' => $rule->frequency, 'interval_days' => max(1, $rule->intervalDays), 'weekdays' => implode(',', $rule->weekdays),
            'month_day' => $rule->monthDay, 'at_time' => $rule->time, 'timezone' => $rule->timezone,
            'starts_on' => $rule->startsOn, 'ends_on' => $rule->endsOn, 'max_count' => $rule->maxCount,
            'next_run_at' => $nextRunAt === null ? null : DbTime::format($nextRunAt), 'enabled' => $nextRunAt === null ? 0 : 1,
            'created_by' => $context->userId, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $this->find($context, $publicId) ?? throw new \LogicException('The inserted rule disappeared.');
    }

    public function setEnabled(WorkspaceContext $context, RecurringPost $recurring, bool $enabled, ?DateTimeImmutable $nextRunAt): void
    {
        $this->scoped($context, 'recurring_rules')->where('id', '=', $recurring->id)->update([
            'enabled' => $enabled ? 1 : 0, 'next_run_at' => $nextRunAt === null ? null : DbTime::format($nextRunAt), 'updated_at' => DbTime::format($this->clock->now()),
        ]);
    }

    public function delete(WorkspaceContext $context, RecurringPost $recurring): void
    {
        $this->scoped($context, 'recurring_rules')->where('id', '=', $recurring->id)->delete();
    }

    /**
     * Record an occurrence as made. Returns false when this occurrence was already taken (a second tick, a second worker): the
     * unique key lets exactly one caller win.
     */
    public function claimOccurrence(WorkspaceContext $context, RecurringPost $recurring, DateTimeImmutable $at): bool
    {
        if ($recurring->workspaceId !== $context->workspaceId) {
            throw new \LogicException('A rule of another workspace.');
        }

        return $this->db->execute('INSERT IGNORE INTO recurring_runs (rule_id, occurrence_at, created_at) VALUES (?, ?, ?)', [$recurring->id, DbTime::format($at), DbTime::format($this->clock->now())]) === 1;
    }

    public function attachRun(WorkspaceContext $context, RecurringPost $recurring, DateTimeImmutable $at, int $postId): void
    {
        if ($recurring->workspaceId === $context->workspaceId) {
            $this->db->execute('UPDATE recurring_runs SET post_id = ? WHERE rule_id = ? AND occurrence_at = ?', [$postId, $recurring->id, DbTime::format($at)]);
        }
    }

    public function advance(WorkspaceContext $context, RecurringPost $recurring, int $doneCount, ?DateTimeImmutable $nextRunAt, ?string $error): void
    {
        $this->scoped($context, 'recurring_rules')->where('id', '=', $recurring->id)->update([
            'done_count' => $doneCount, 'next_run_at' => $nextRunAt === null ? null : DbTime::format($nextRunAt),
            'enabled' => $nextRunAt === null ? 0 : ($recurring->enabled ? 1 : 0), 'last_error' => $error === null ? null : mb_substr($error, 0, 255),
            'updated_at' => DbTime::format($this->clock->now()),
        ]);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function hydrate(array $row): RecurringPost
    {
        $weekdays = array_values(array_filter(array_map('intval', explode(',', (string) $row['weekdays'])), static fn (int $d): bool => $d >= 1 && $d <= 7));
        $rule = new RecurrenceRule(
            (string) $row['frequency'],
            (int) $row['interval_days'],
            $weekdays,
            isset($row['month_day']) ? (int) $row['month_day'] : null,
            (string) $row['at_time'],
            (string) $row['timezone'],
            substr((string) $row['starts_on'], 0, 10),
            isset($row['ends_on']) ? substr((string) $row['ends_on'], 0, 10) : null,
            isset($row['max_count']) ? (int) $row['max_count'] : null,
        );

        return new RecurringPost((int) $row['id'], (string) $row['public_id'], (int) $row['workspace_id'], (int) $row['post_id'], $rule, (int) $row['done_count'], DbTime::parse($row['next_run_at']), (int) $row['enabled'] === 1, isset($row['last_error']) ? (string) $row['last_error'] : null);
    }
}
