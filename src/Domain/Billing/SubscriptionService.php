<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Audit\AuditLog;
use App\Domain\Channel\ChannelSystem;
use App\Domain\Post\PublicationSystem;
use App\Kernel\Config;
use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;
use DateTimeImmutable;

/**
 * The life of a subscription that does not involve a payment page: the trial a new workspace starts with, falling back to Free,
 * cancelling and resuming automatic renewal, booking a downgrade for the end of the period, and keeping channels in step with the
 * plan (extra channels are paused, never deleted). Money-related transitions (payment succeeded, renewal) are in `BillingService`.
 */
final class SubscriptionService
{
    public function __construct(
        private readonly Connection $db,
        private readonly SubscriptionRepository $subscriptions,
        private readonly PlanRepository $plans,
        private readonly Entitlements $entitlements,
        private readonly ChannelSystem $channels,
        private readonly PublicationSystem $publications,
        private readonly BillingMailer $mailer,
        private readonly AuditLog $audit,
        private readonly Config $config,
        private readonly Clock $clock,
    ) {
    }

    /**
     * A brand-new personal workspace: the trial plan for `billing.trial.days` days, without a card.
     */
    public function startTrial(int $workspaceId): Subscription
    {
        $existing = $this->subscriptions->findByWorkspace($workspaceId);
        if ($existing !== null) {
            return $existing;
        }
        $plan = $this->plans->findByCode($this->config->string('billing.trial.plan', 'pro'));
        $days = $this->config->int('billing.trial.days', 14);
        if ($plan === null || $plan->isFree() || $days <= 0) {
            return $this->startFree($workspaceId);
        }
        $subscription = $this->subscriptions->create($workspaceId, $plan->id, SubscriptionStatus::Trialing, $this->clock->now()->modify(sprintf('+%d days', $days)), $this->currency());
        $this->syncWorkspacePlan($workspaceId, $plan->id);
        $this->audit->record('billing.trial_started', null, 'subscription', $subscription->publicId, ['plan' => $plan->code, 'days' => $days], $workspaceId);

        return $subscription;
    }

    public function startFree(int $workspaceId): Subscription
    {
        $existing = $this->subscriptions->findByWorkspace($workspaceId);
        if ($existing !== null) {
            return $existing;
        }
        $free = $this->plans->free();
        $subscription = $this->subscriptions->create($workspaceId, $free->id, SubscriptionStatus::Active, null, $this->currency());
        $this->syncWorkspacePlan($workspaceId, $free->id);

        return $subscription;
    }

    /**
     * The subscription of a workspace; workspaces from before billing existed get a Free one on first look.
     */
    public function ensure(int $workspaceId): Subscription
    {
        return $this->subscriptions->findByWorkspace($workspaceId) ?? $this->startFree($workspaceId);
    }

    /**
     * Give a plan without a payment (the owner's gift, a manual fix, tests). The period runs from now.
     */
    public function grant(int $workspaceId, Plan $plan, BillingPeriod $period, ?int $actorId = null): Subscription
    {
        $subscription = $this->ensure($workspaceId);
        $now = $this->clock->now();
        $end = $period->addTo($now);
        $this->subscriptions->update($subscription->id, [
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active->value,
            'period' => $plan->isFree() ? null : $period->value,
            'price_amount' => $plan->priceFor($period, $subscription->currency) ?? 0,
            'current_period_start' => $plan->isFree() ? null : DbTime::format($now),
            'current_period_end' => $plan->isFree() ? null : DbTime::format($end),
            'trial_ends_at' => null,
            'cancel_at_period_end' => 0,
            'pending_plan_id' => null,
            'pending_period' => null,
            'renewal_attempts' => 0,
            'first_attempt_at' => null,
            'next_renewal_attempt_at' => null,
            'last_failure' => null,
        ]);
        $this->syncWorkspacePlan($workspaceId, $plan->id);
        $this->enforce($workspaceId);
        $this->audit->record('billing.plan_granted', $actorId, 'subscription', $subscription->publicId, ['plan' => $plan->code, 'period' => $period->value], $workspaceId);

        return $this->ensure($workspaceId);
    }

    /**
     * Stop charging at the end of the paid period. The plan keeps working until then.
     */
    public function cancelRenewal(int $workspaceId, ?int $actorId): void
    {
        $subscription = $this->ensure($workspaceId);
        if (!$subscription->hasPaidPeriod() || $subscription->cancelAtPeriodEnd) {
            return;
        }
        $this->subscriptions->update($subscription->id, ['cancel_at_period_end' => 1, 'pending_plan_id' => null, 'pending_period' => null, 'next_renewal_attempt_at' => null]);
        $this->audit->record('billing.renewal_canceled', $actorId, 'subscription', $subscription->publicId, [], $workspaceId);
    }

    /**
     * Take the cancellation back. Needs a saved card to renew by itself; without one the owner is asked to pay by hand near the end.
     */
    public function resumeRenewal(int $workspaceId, ?int $actorId): void
    {
        $subscription = $this->ensure($workspaceId);
        if (!$subscription->hasPaidPeriod() || !$subscription->cancelAtPeriodEnd) {
            return;
        }
        $this->subscriptions->update($subscription->id, ['cancel_at_period_end' => 0, 'next_renewal_attempt_at' => $this->firstAttemptAt($subscription->currentPeriodEnd)]);
        $this->audit->record('billing.renewal_resumed', $actorId, 'subscription', $subscription->publicId, [], $workspaceId);
    }

    /**
     * Book a cheaper plan (or period) for the moment the paid period ends. Nothing changes until then.
     */
    public function scheduleChange(int $workspaceId, Plan $plan, BillingPeriod $period, ?int $actorId): void
    {
        $subscription = $this->ensure($workspaceId);
        if (!$subscription->hasPaidPeriod()) {
            throw new BillingException('Сейчас нет оплаченного периода, менять нечего.');
        }
        $this->subscriptions->update($subscription->id, [
            'pending_plan_id' => $plan->id,
            'pending_period' => $period->value,
            'cancel_at_period_end' => 0,
            'next_renewal_attempt_at' => $subscription->paymentMethodId === null ? null : $this->firstAttemptAt($subscription->currentPeriodEnd),
        ]);
        $this->audit->record('billing.change_scheduled', $actorId, 'subscription', $subscription->publicId, ['plan' => $plan->code, 'period' => $period->value], $workspaceId);
    }

    public function clearScheduledChange(int $workspaceId, ?int $actorId): void
    {
        $subscription = $this->ensure($workspaceId);
        if ($subscription->pendingPlanId === null) {
            return;
        }
        $this->subscriptions->update($subscription->id, ['pending_plan_id' => null, 'pending_period' => null]);
        $this->audit->record('billing.change_unscheduled', $actorId, 'subscription', $subscription->publicId, [], $workspaceId);
    }

    /**
     * Move to Free right now: a finished trial, a cancelled subscription whose period ended, or one that could not be renewed.
     *
     * @param string $reason trial | canceled | unpaid (selects the email)
     * @return int how many channels were paused
     */
    public function dropToFree(Subscription $subscription, string $reason, bool $notify = true): int
    {
        $free = $this->plans->free();
        $this->subscriptions->update($subscription->id, [
            'plan_id' => $free->id,
            'status' => SubscriptionStatus::Active->value,
            'period' => null,
            'price_amount' => 0,
            'current_period_start' => null,
            'current_period_end' => null,
            'trial_ends_at' => null,
            'cancel_at_period_end' => 0,
            'pending_plan_id' => null,
            'pending_period' => null,
            'renewal_attempts' => 0,
            'first_attempt_at' => null,
            'next_renewal_attempt_at' => null,
        ]);
        $this->syncWorkspacePlan($subscription->workspaceId, $free->id);
        $paused = $this->enforce($subscription->workspaceId);
        $this->audit->record('billing.downgraded_to_free', null, 'subscription', $subscription->publicId, ['reason' => $reason, 'channels_paused' => $paused], $subscription->workspaceId);
        if ($notify) {
            $this->mailer->downgraded($subscription->workspaceId, $reason, $paused);
        }

        return $paused;
    }

    /**
     * After a plan got smaller: pause the channels beyond the allowance (the newest first) and cancel the posts planned for them.
     * Members, storage and workspaces over the new limits are left alone; only new additions are refused (see `Entitlements`).
     *
     * @return int how many channels were paused
     */
    public function enforce(int $workspaceId): int
    {
        $limit = $this->entitlements->limit($workspaceId, 'channels');
        if ($limit === null) {
            return 0;
        }
        $paused = 0;
        $plan = $this->entitlements->plan($workspaceId);
        $reason = sprintf('Поставлен на паузу: на тарифе «%s» доступно каналов: %d.', $plan->name, $limit);
        $this->db->transaction(function () use ($workspaceId, $limit, $reason, &$paused): void {
            foreach (array_slice($this->channels->occupying($workspaceId), $limit) as $channel) {
                $this->channels->pause($channel, $reason);
                $this->publications->cancelForChannel($channel->id, 'Канал «' . $channel->displayName() . '» поставлен на паузу: превышен лимит тарифа.');
                ++$paused;
            }
        });
        if ($paused > 0) {
            $this->audit->record('billing.channels_paused', null, 'workspace', (string) $workspaceId, ['count' => $paused], $workspaceId);
        }

        return $paused;
    }

    /**
     * When the first automatic charge of a period happens: `billing.renewal.lead_days` before it ends.
     */
    public function firstAttemptAt(?DateTimeImmutable $periodEnd): ?string
    {
        if ($periodEnd === null) {
            return null;
        }
        $at = $periodEnd->modify(sprintf('-%d days', $this->config->int('billing.renewal.lead_days', 3)));

        return DbTime::format($at < $this->clock->now() ? $this->clock->now() : $at);
    }

    public function syncWorkspacePlan(int $workspaceId, int $planId): void
    {
        $this->db->table('workspaces')->where('id', '=', $workspaceId)->update(['plan_id' => $planId]);
    }

    private function currency(): string
    {
        return $this->config->string('billing.currency', 'RUB');
    }
}
