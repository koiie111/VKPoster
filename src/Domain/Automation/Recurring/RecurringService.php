<?php

declare(strict_types=1);

namespace App\Domain\Automation\Recurring;

use App\Domain\Audit\AuditLog;
use App\Domain\Automation\AutomationException;
use App\Domain\Billing\Entitlements;
use App\Domain\Billing\PlanLimitException;
use App\Domain\Post\Post;
use App\Domain\Post\PostException;
use App\Domain\Post\PostRepository;
use App\Domain\Post\PostService;
use App\Domain\Post\PublishingContexts;
use App\Domain\Workspace\Permissions;
use App\Domain\Workspace\WorkspaceContext;
use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;
use DateTimeImmutable;

/**
 * Recurring posts: a master post and a rule ("every Monday at 10:00 until the end of the year"). A day before each occurrence the
 * scheduler makes a copy of the master and plans it, so the copy shows in the calendar and goes out through the usual pipeline; the
 * master itself is never published.
 *
 * Each occurrence is claimed once (`recurring_runs` has a unique key), so a repeated tick or two workers never make two copies. An
 * occurrence that was missed by more than ten minutes (the service was down) is skipped rather than sent late.
 */
final class RecurringService
{
    public const MAX_RULES = 50;
    /** How long before its time an occurrence is made. */
    private const HORIZON = '+1 day';
    private const MISSED_AFTER_MINUTES = 10;

    public function __construct(
        private readonly Connection $db,
        private readonly RecurringRepository $rules,
        private readonly PostRepository $posts,
        private readonly PostService $service,
        private readonly Entitlements $entitlements,
        private readonly Permissions $permissions,
        private readonly PublishingContexts $contexts,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @throws AutomationException
     */
    public function create(WorkspaceContext $context, Post $master, RecurrenceRule $rule): RecurringPost
    {
        $this->authorize($context);
        if (!$this->service->canSee($context, $master)) {
            throw new AutomationException('Пост не найден.');
        }
        $problems = $rule->problems();
        if ($problems !== []) {
            throw new AutomationException($problems[0]);
        }
        if ($this->rules->count($context) >= self::MAX_RULES) {
            throw new AutomationException(sprintf('Повторов может быть не больше %d.', self::MAX_RULES));
        }
        $next = $rule->next($this->clock->now());
        if ($next === null) {
            throw new AutomationException('По этим настройкам пост больше не выйдет ни разу. Проверьте даты.');
        }
        $recurring = $this->rules->create($context, $master->id, $rule, $next);
        $this->audit->record('recurring.created', $context->userId, 'recurring', $recurring->publicId, ['post' => $master->publicId], $context->workspaceId);

        return $recurring;
    }

    public function setEnabled(WorkspaceContext $context, RecurringPost $recurring, bool $enabled): void
    {
        $this->authorize($context);
        // A paused rule keeps its next time (it only stops making copies); a rule without a next time is finished.
        $next = $enabled ? $recurring->rule->next($this->clock->now(), $recurring->doneCount) : $recurring->nextRunAt;
        if ($enabled && $next === null) {
            throw new AutomationException('Повторы по этому правилу закончились. Создайте новое.');
        }
        $this->rules->setEnabled($context, $recurring, $enabled, $next);
        $this->audit->record($enabled ? 'recurring.resumed' : 'recurring.paused', $context->userId, 'recurring', $recurring->publicId, [], $context->workspaceId);
    }

    public function delete(WorkspaceContext $context, RecurringPost $recurring): void
    {
        $this->authorize($context);
        $this->rules->delete($context, $recurring);
        $this->audit->record('recurring.deleted', $context->userId, 'recurring', $recurring->publicId, [], $context->workspaceId);
    }

    /**
     * Every minute: make the copies that are due within a day.
     *
     * @return int copies made
     */
    public function tick(): int
    {
        $horizon = $this->clock->now()->modify(self::HORIZON);
        $made = 0;
        $due = $this->db->table('recurring_rules')->where('enabled', '=', 1)->where('next_run_at', '<=', DbTime::format($horizon))->orderBy('next_run_at')->limit(200)->get();
        foreach ($due as $row) {
            $context = $this->contexts->forWorkspace((int) $row['workspace_id'], isset($row['created_by']) ? (int) $row['created_by'] : null);
            $recurring = $context === null ? null : $this->rules->findById($context, (int) $row['id']);
            if ($context !== null && $recurring !== null) {
                $made += $this->run($context, $recurring, $horizon);
            }
        }

        return $made;
    }

    /**
     * Make every occurrence of the rule that is due within the horizon (normally one; several after a long pause).
     */
    private function run(WorkspaceContext $context, RecurringPost $recurring, DateTimeImmutable $horizon): int
    {
        $made = 0;
        $done = $recurring->doneCount;
        $at = $recurring->nextRunAt;
        $error = null;
        // The loop is bounded: a rule has at most a few occurrences inside one day, and a backlog is skipped quickly.
        for ($guard = 0; $at !== null && $at <= $horizon && $guard < 50; ++$guard) {
            $missed = $at < $this->clock->now()->modify('-' . self::MISSED_AFTER_MINUTES . ' minutes');
            if (!$missed && $this->rules->claimOccurrence($context, $recurring, $at)) {
                $error = $this->makeCopy($context, $recurring, $at);
                if ($error === null) {
                    ++$made;
                }
            }
            ++$done;
            $at = $recurring->rule->next($at, $done);
        }
        $this->rules->advance($context, $recurring, $done, $at, $error);

        return $made;
    }

    /**
     * @return string|null the reason in Russian when the copy could not be planned
     */
    private function makeCopy(WorkspaceContext $context, RecurringPost $recurring, DateTimeImmutable $at): ?string
    {
        $master = $this->posts->findById($context, $recurring->postId);
        if ($master === null) {
            return 'Исходный пост удалён.';
        }
        try {
            $copy = $this->service->duplicate($context, $master);
            $this->rules->attachRun($context, $recurring, $at, $copy->id);
            $now = $at <= $this->clock->now()->modify('+30 seconds');
            $this->service->planExisting($context, $copy, $at, $now);
        } catch (PostException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * @throws AutomationException
     */
    private function authorize(WorkspaceContext $context): void
    {
        if (!$this->permissions->allows($context->role, 'posts.publish')) {
            throw new AutomationException('Повторы настраивают редакторы и администраторы.', forbidden: true);
        }
        try {
            $this->entitlements->assertFeature($context->workspaceId, 'slots', 'Повторяющиеся посты');
        } catch (PlanLimitException $e) {
            throw new AutomationException($e->getMessage(), planLimit: true);
        }
    }
}
