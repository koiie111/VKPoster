<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Channel\ChannelSystem;
use App\Kernel\Config;
use App\Kernel\Database\Connection;
use App\Support\DbTime;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The single place that answers "does the plan allow it?". Services ask it before they do the work (so a limit holds for every entry
 * point, not only for the page), pages ask it to show meters and to hide what is not included. A workspace without a subscription row
 * is on the Free plan.
 *
 * Counting rules (also shown to customers): channels count while they are not paused; a month is counted in the workspace's time
 * zone and holds every post planned or published for that month; members count invitations that are still open.
 */
final class Entitlements
{
    /** Statuses of a post that occupy the monthly allowance (drafts and cancelled posts do not). */
    private const COUNTED_POST_STATUSES = ['scheduled', 'publishing', 'published', 'partially_failed', 'failed'];

    public function __construct(
        private readonly Connection $db,
        private readonly PlanRepository $plans,
        private readonly SubscriptionRepository $subscriptions,
        private readonly ChannelSystem $channels,
        private readonly Config $config,
    ) {
    }

    public function for(int $workspaceId): Entitlement
    {
        $subscription = $this->subscriptions->findByWorkspace($workspaceId);
        $plan = $subscription === null ? null : $this->plans->find($subscription->planId);

        return new Entitlement($plan ?? $this->plans->free(), $plan === null ? null : $subscription);
    }

    public function plan(int $workspaceId): Plan
    {
        return $this->for($workspaceId)->plan;
    }

    /** null = unlimited. */
    public function limit(int $workspaceId, string $key): ?int
    {
        return $this->for($workspaceId)->limit($key);
    }

    public function hasFeature(int $workspaceId, string $feature): bool
    {
        return $this->for($workspaceId)->hasFeature($feature);
    }

    /**
     * @throws PlanLimitException when the plan does not include the feature
     */
    public function assertFeature(int $workspaceId, string $feature, string $what): void
    {
        $plan = $this->plan($workspaceId);
        if (!$plan->hasFeature($feature)) {
            throw new PlanLimitException('feature:' . $feature, sprintf('«%s» недоступно на тарифе «%s». Перейдите на тариф, в который эта возможность входит.', $what, $plan->name));
        }
    }

    // ---- channels -------------------------------------------------------------------------------------------------

    /**
     * How many more channels may be switched on (null = no limit).
     */
    public function channelsLeft(int $workspaceId): ?int
    {
        $limit = $this->limit($workspaceId, 'channels');

        return $limit === null ? null : max(0, $limit - $this->channels->countOccupying($workspaceId));
    }

    public function canAddChannel(int $workspaceId): bool
    {
        try {
            $this->assertCanAddChannel($workspaceId);

            return true;
        } catch (PlanLimitException) {
            return false;
        }
    }

    /**
     * Used when a channel is connected and when a paused one is resumed.
     *
     * @throws PlanLimitException
     */
    public function assertCanAddChannel(int $workspaceId): void
    {
        $ceiling = $this->config->int('platforms.channels.max_per_workspace', 1000);
        if ($this->channels->countAll($workspaceId) >= $ceiling) {
            throw new PlanLimitException('channels', sprintf('Достигнут лимит каналов в пространстве: %d. Отключите ненужный канал, чтобы подключить новый.', $ceiling));
        }
        $left = $this->channelsLeft($workspaceId);
        if ($left !== null && $left <= 0) {
            $plan = $this->plan($workspaceId);

            throw new PlanLimitException('channels', sprintf('На тарифе «%s» можно подключить до %d %s. Поставьте лишний канал на паузу или перейдите на тариф выше.', $plan->name, (int) $plan->limit('channels'), self::upTo((int) $plan->limit('channels'), 'канала', 'каналов')));
        }
    }

    // ---- posts ----------------------------------------------------------------------------------------------------

    /**
     * Whether a post with this status is already counted against the monthly allowance (drafts and cancelled posts are not).
     */
    public static function countsTowardMonthlyLimit(string $postStatus): bool
    {
        return in_array($postStatus, self::COUNTED_POST_STATUSES, true);
    }

    /**
     * Posts planned for (or published in) the month of `$at`, in the workspace's time zone.
     */
    public function postsInMonth(int $workspaceId, DateTimeImmutable $at): int
    {
        [$from, $to] = $this->monthBounds($workspaceId, $at);
        $marks = implode(',', array_fill(0, count(self::COUNTED_POST_STATUSES), '?'));
        $rows = $this->db->select(
            'SELECT COUNT(*) AS c FROM posts WHERE workspace_id = ? AND status IN (' . $marks . ') AND scheduled_at >= ? AND scheduled_at < ?',
            [$workspaceId, ...self::COUNTED_POST_STATUSES, DbTime::format($from), DbTime::format($to)],
        );

        return (int) ($rows[0]['c'] ?? 0);
    }

    /**
     * Posts that may still be planned for the month of `$at` (null = unlimited).
     */
    public function postsLeftInMonth(int $workspaceId, DateTimeImmutable $at): ?int
    {
        $limit = $this->limit($workspaceId, 'posts_per_month');

        return $limit === null ? null : max(0, $limit - $this->postsInMonth($workspaceId, $at));
    }

    /**
     * @param DateTimeImmutable|null $alreadyPlannedAt when the post is already counted (it is being moved), the time it is planned for now:
     *                                                 moving within one month costs nothing, moving into a full month does
     * @throws PlanLimitException
     */
    public function assertCanPlanPost(int $workspaceId, DateTimeImmutable $at, ?DateTimeImmutable $alreadyPlannedAt = null): void
    {
        $left = $this->postsLeftInMonth($workspaceId, $at);
        if ($left === null || $left > 0) {
            return;
        }
        if ($alreadyPlannedAt !== null && $this->monthKey($workspaceId, $alreadyPlannedAt) === $this->monthKey($workspaceId, $at)) {
            return;
        }
        $plan = $this->plan($workspaceId);

        throw new PlanLimitException('posts', sprintf('На тарифе «%s» можно планировать до %d %s в месяц, и на этот месяц они уже заняты. Выберите другой месяц или перейдите на тариф выше.', $plan->name, (int) $plan->limit('posts_per_month'), self::upTo((int) $plan->limit('posts_per_month'), 'поста', 'постов')));
    }

    // ---- people and places ----------------------------------------------------------------------------------------

    /**
     * Members plus invitations that can still be accepted.
     */
    public function membersUsed(int $workspaceId, DateTimeImmutable $now): int
    {
        $members = $this->db->table('workspace_members')->where('workspace_id', '=', $workspaceId)->count();
        $invited = $this->db->select(
            'SELECT COUNT(*) AS c FROM invitations WHERE workspace_id = ? AND accepted_at IS NULL AND revoked_at IS NULL AND expires_at > ?',
            [$workspaceId, DbTime::format($now)],
        );

        return $members + (int) ($invited[0]['c'] ?? 0);
    }

    /**
     * @throws PlanLimitException
     */
    public function assertCanAddMember(int $workspaceId, DateTimeImmutable $now): void
    {
        $limit = $this->limit($workspaceId, 'members');
        if ($limit !== null && $this->membersUsed($workspaceId, $now) >= $limit) {
            $plan = $this->plan($workspaceId);

            throw new PlanLimitException('members', sprintf('На тарифе «%s» в команде может быть до %d %s (с теми, кого вы уже пригласили). Перейдите на тариф выше, чтобы позвать ещё.', $plan->name, $limit, self::upTo($limit, 'человека', 'человек')));
        }
    }

    /**
     * Library size in bytes (null = unlimited).
     */
    public function storageBytes(int $workspaceId): ?int
    {
        return $this->limit($workspaceId, 'storage_bytes');
    }

    /**
     * How many workspaces a person may own: the most generous plan among the workspaces they own (null = no limit besides the global one).
     */
    public function workspacesAllowed(int $userId): ?int
    {
        $best = 1;
        foreach ($this->db->table('workspaces')->where('owner_id', '=', $userId)->get() as $row) {
            $limit = $this->limit((int) $row['id'], 'workspaces');
            if ($limit === null) {
                return null;
            }
            $best = max($best, $limit);
        }

        return $best;
    }

    /**
     * @throws PlanLimitException
     */
    public function assertCanCreateWorkspace(int $userId): void
    {
        $allowed = $this->workspacesAllowed($userId);
        if ($allowed === null) {
            return;
        }
        $owned = $this->db->table('workspaces')->where('owner_id', '=', $userId)->count();
        if ($owned >= $allowed) {
            throw new PlanLimitException('workspaces', sprintf('Ваши тарифы позволяют иметь до %d %s. Чтобы создать ещё одно, перейдите на тариф выше в одном из ваших пространств.', $allowed, self::upTo($allowed, 'пространства', 'пространств')));
        }
    }

    // ---- the meters on the billing page ---------------------------------------------------------------------------

    /**
     * @return array{channels: array{used: int, limit: int|null}, posts: array{used: int, limit: int|null}, members: array{used: int, limit: int|null}, storage: array{used: int, limit: int|null}}
     */
    public function usage(int $workspaceId, DateTimeImmutable $now): array
    {
        $entitlement = $this->for($workspaceId);
        $storage = $this->db->select('SELECT COALESCE(SUM(size), 0) AS total FROM media WHERE workspace_id = ?', [$workspaceId]);

        return [
            'channels' => ['used' => $this->channels->countOccupying($workspaceId), 'limit' => $entitlement->limit('channels')],
            'posts' => ['used' => $this->postsInMonth($workspaceId, $now), 'limit' => $entitlement->limit('posts_per_month')],
            'members' => ['used' => $this->membersUsed($workspaceId, $now), 'limit' => $entitlement->limit('members')],
            'storage' => ['used' => (int) ($storage[0]['total'] ?? 0), 'limit' => $entitlement->limit('storage_bytes')],
        ];
    }

    // ---- helpers --------------------------------------------------------------------------------------------------

    /**
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable} first instant of the month and of the next one (UTC)
     */
    private function monthBounds(int $workspaceId, DateTimeImmutable $at): array
    {
        $local = $at->setTimezone($this->timezone($workspaceId));
        $start = $local->setDate((int) $local->format('Y'), (int) $local->format('n'), 1)->setTime(0, 0);

        return [$start->setTimezone(new DateTimeZone('UTC')), $start->modify('+1 month')->setTimezone(new DateTimeZone('UTC'))];
    }

    private function monthKey(int $workspaceId, DateTimeImmutable $at): string
    {
        return $at->setTimezone($this->timezone($workspaceId))->format('Y-m');
    }

    private function timezone(int $workspaceId): DateTimeZone
    {
        $row = $this->db->table('workspaces')->where('id', '=', $workspaceId)->first();
        try {
            return new DateTimeZone(is_array($row) ? (string) $row['timezone'] : 'UTC');
        } catch (\Exception) {
            return new DateTimeZone('UTC');
        }
    }

    /**
     * The noun after "до N" (genitive): 1 канала, 2 каналов, 21 канала, 11 каналов.
     */
    public static function upTo(int $n, string $singular, string $plural): string
    {
        return abs($n) % 10 === 1 && abs($n) % 100 !== 11 ? $singular : $plural;
    }
}
