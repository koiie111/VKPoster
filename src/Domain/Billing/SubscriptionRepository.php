<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;
use DateTimeImmutable;
use Symfony\Component\Uid\Ulid;

/**
 * Persistence of `subscriptions`. Not workspace-scoped on purpose: the renewal job and the payment webhooks run without a signed-in
 * member, so callers pass the workspace id they resolved themselves (pages through `ResolveWorkspace`, jobs from their own queries).
 */
final class SubscriptionRepository
{
    private const BATCH = 200;

    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    public function create(int $workspaceId, int $planId, SubscriptionStatus $status, ?DateTimeImmutable $trialEndsAt = null, string $currency = 'RUB'): Subscription
    {
        $now = DbTime::format($this->clock->now());
        $publicId = (string) new Ulid();
        $this->db->table('subscriptions')->insert([
            'public_id' => $publicId,
            'workspace_id' => $workspaceId,
            'plan_id' => $planId,
            'status' => $status->value,
            'currency' => $currency,
            'trial_ends_at' => $trialEndsAt === null ? null : DbTime::format($trialEndsAt),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->findByPublicId($publicId) ?? throw new \RuntimeException('The subscription was not saved.');
    }

    public function findByWorkspace(int $workspaceId): ?Subscription
    {
        $row = $this->db->table('subscriptions')->where('workspace_id', '=', $workspaceId)->first();

        return $row === null ? null : self::hydrate($row);
    }

    public function findByPublicId(string $publicId): ?Subscription
    {
        if (!Ulid::isValid($publicId)) {
            return null;
        }
        $row = $this->db->table('subscriptions')->where('public_id', '=', strtoupper($publicId))->first();

        return $row === null ? null : self::hydrate($row);
    }

    /**
     * Read the row inside the caller's transaction and lock it, so two payments or two ticks cannot change one subscription at once.
     */
    public function lock(int $workspaceId): ?Subscription
    {
        $rows = $this->db->table('subscriptions')->where('workspace_id', '=', $workspaceId)->forUpdate()->get();

        return $rows === [] ? null : self::hydrate($rows[0]);
    }

    /**
     * @param array<string, scalar|null> $values column => value (dates already formatted with `DbTime`)
     */
    public function update(int $id, array $values): void
    {
        $values['updated_at'] = DbTime::format($this->clock->now());
        $this->db->table('subscriptions')->where('id', '=', $id)->update($values);
    }

    /**
     * Trials that are over.
     *
     * @return list<Subscription>
     */
    public function trialsEnded(DateTimeImmutable $now): array
    {
        return $this->many($this->db->table('subscriptions')->where('status', '=', SubscriptionStatus::Trialing->value)->where('trial_ends_at', '<=', DbTime::format($now))->orderBy('trial_ends_at')->limit(self::BATCH)->get());
    }

    /**
     * Trials that end before `$until` and whose owner has not been warned yet.
     *
     * @return list<Subscription>
     */
    public function trialsToRemind(DateTimeImmutable $now, DateTimeImmutable $until): array
    {
        return $this->many($this->db->table('subscriptions')->where('status', '=', SubscriptionStatus::Trialing->value)->where('trial_ends_at', '>', DbTime::format($now))->where('trial_ends_at', '<=', DbTime::format($until))->whereNull('trial_reminded_at')->limit(self::BATCH)->get());
    }

    /**
     * Subscriptions whose automatic renewal is due now (the first attempt or a retry).
     *
     * @return list<Subscription>
     */
    public function renewalsDue(DateTimeImmutable $now): array
    {
        return $this->many($this->db->table('subscriptions')->where('next_renewal_attempt_at', '<=', DbTime::format($now))->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::PastDue->value])->orderBy('next_renewal_attempt_at')->limit(self::BATCH)->get());
    }

    /**
     * Paid periods that have ended and will not renew by themselves (renewal switched off, or no way to pay): the plan falls to Free
     * once the grace period is over.
     *
     * @return list<Subscription>
     */
    public function periodsEndedBefore(DateTimeImmutable $limit): array
    {
        return $this->many($this->db->table('subscriptions')->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::PastDue->value])->whereNotNull('current_period_end')->where('current_period_end', '<=', DbTime::format($limit))->orderBy('current_period_end')->limit(self::BATCH)->get());
    }

    /**
     * @return int how many subscriptions use this plan (a plan in use must not be deleted)
     */
    public function countOnPlan(int $planId): int
    {
        return $this->db->table('subscriptions')->where('plan_id', '=', $planId)->count();
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<Subscription>
     */
    private function many(array $rows): array
    {
        return array_map(self::hydrate(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function hydrate(array $row): Subscription
    {
        $period = static fn (mixed $value): ?BillingPeriod => is_string($value) ? BillingPeriod::tryFrom($value) : null;

        return new Subscription(
            (int) $row['id'],
            (string) $row['public_id'],
            (int) $row['workspace_id'],
            (int) $row['plan_id'],
            SubscriptionStatus::from((string) $row['status']),
            $period($row['period'] ?? null),
            (string) $row['currency'],
            (int) $row['price_amount'],
            DbTime::parse($row['current_period_start'] ?? null),
            DbTime::parse($row['current_period_end'] ?? null),
            DbTime::parse($row['trial_ends_at'] ?? null),
            DbTime::parse($row['trial_reminded_at'] ?? null),
            (int) $row['cancel_at_period_end'] === 1,
            isset($row['pending_plan_id']) ? (int) $row['pending_plan_id'] : null,
            $period($row['pending_period'] ?? null),
            isset($row['payment_method_id']) ? (int) $row['payment_method_id'] : null,
            (int) $row['renewal_attempts'],
            DbTime::parse($row['first_attempt_at'] ?? null),
            DbTime::parse($row['next_renewal_attempt_at'] ?? null),
            isset($row['last_failure']) ? (string) $row['last_failure'] : null,
            DbTime::parse($row['created_at']) ?? new DateTimeImmutable('@0'),
        );
    }
}
