<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Audit\AuditLog;
use App\Integrations\Payments\Contracts\GatewayException;
use App\Integrations\Payments\GatewayRegistry;
use App\Kernel\Config;
use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;
use Psr\Log\LoggerInterface;

/**
 * The periodic work of billing (`tick()`, run by the scheduler every hour and by `billing:renew`):
 *
 * 1. cancel invoices nobody paid, ask providers about payments that stayed open (lost notifications);
 * 2. warn about trials that are about to end;
 * 3. charge the saved card of subscriptions that renew: first `renewal.lead_days` before the end, after a failure again after each of
 *    `renewal.retry_days` (counted from the first attempt), each failure with an email;
 * 4. after the paid period ends the plan keeps working for `renewal.grace_days`, then the workspace falls back to Free (nothing is deleted);
 *    a cancelled subscription falls back as soon as its period ends, a trial as soon as it is over.
 *
 * Every step is safe to repeat and one broken subscription never stops the others.
 */
final class RenewalService
{
    public function __construct(
        private readonly Connection $db,
        private readonly SubscriptionRepository $subscriptions,
        private readonly PlanRepository $plans,
        private readonly InvoiceRepository $invoices,
        private readonly PaymentRepository $payments,
        private readonly PaymentMethodRepository $methods,
        private readonly GatewayRegistry $gateways,
        private readonly BillingService $billing,
        private readonly SubscriptionService $subscriptionService,
        private readonly BillingMailer $mailer,
        private readonly \App\Domain\Workspace\WorkspaceRepository $workspaces,
        private readonly \App\Domain\User\UserRepository $users,
        private readonly AuditLog $audit,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, int> what was done, by kind
     */
    public function tick(): array
    {
        $now = $this->clock->now();
        $summary = ['invoices_voided' => $this->invoices->voidExpired($now), 'payments_checked' => $this->billing->reconcilePending(), 'trial_reminders' => 0, 'renewals_paid' => 0, 'renewals_failed' => 0, 'renewals_waiting' => 0, 'past_due' => 0, 'dropped_to_free' => 0];

        foreach ($this->subscriptions->trialsToRemind($now, $now->modify('+3 days')) as $subscription) {
            $this->guard($subscription, function () use ($subscription, &$summary): void {
                if ($subscription->trialEndsAt !== null) {
                    $this->mailer->trialEnding($subscription->workspaceId, $subscription->trialEndsAt);
                }
                $this->subscriptions->update($subscription->id, ['trial_reminded_at' => DbTime::format($this->clock->now())]);
                ++$summary['trial_reminders'];
            });
        }

        foreach ($this->subscriptions->renewalsDue($now) as $subscription) {
            $this->guard($subscription, function () use ($subscription, &$summary): void {
                $result = $this->renew($subscription);
                $key = ['paid' => 'renewals_paid', 'failed' => 'renewals_failed'][$result] ?? 'renewals_waiting';
                ++$summary[$key];
            });
        }

        foreach ($this->subscriptions->trialsEnded($now) as $subscription) {
            $this->guard($subscription, function () use ($subscription, &$summary): void {
                $this->subscriptionService->dropToFree($subscription, 'trial');
                ++$summary['dropped_to_free'];
            });
        }

        $grace = $this->config->int('billing.renewal.grace_days', 3);
        foreach ($this->subscriptions->periodsEndedBefore($now) as $subscription) {
            $this->guard($subscription, function () use ($subscription, $now, $grace, &$summary): void {
                $end = $subscription->currentPeriodEnd;
                if ($end === null) {
                    return;
                }
                if ($subscription->cancelAtPeriodEnd) {
                    $this->subscriptionService->dropToFree($subscription, 'canceled');
                    ++$summary['dropped_to_free'];

                    return;
                }
                if ($end->modify(sprintf('+%d days', $grace)) <= $now) {
                    $this->subscriptionService->dropToFree($subscription, 'unpaid');
                    ++$summary['dropped_to_free'];

                    return;
                }
                if ($subscription->status === SubscriptionStatus::Active) {
                    $this->subscriptions->update($subscription->id, ['status' => SubscriptionStatus::PastDue->value]);
                    ++$summary['past_due'];
                }
            });
        }

        return $summary;
    }

    /**
     * Try to renew one subscription now.
     *
     * @param bool $force ignore the schedule and the cancellation flag (the owner's `billing:renew --force`)
     * @return string paid | failed | waiting | skipped
     */
    public function renew(Subscription $subscription, bool $force = false): string
    {
        $now = $this->clock->now();
        if (!$subscription->hasPaidPeriod() || ($subscription->cancelAtPeriodEnd && !$force)) {
            $this->subscriptions->update($subscription->id, ['next_renewal_attempt_at' => null]);

            return 'skipped';
        }
        $end = $subscription->currentPeriodEnd ?? $now;
        $plan = $this->plans->find($subscription->pendingPlanId ?? $subscription->planId);
        $period = $subscription->pendingPeriod ?? $subscription->period;
        $price = $plan === null || $period === null ? null : $plan->priceFor($period, $subscription->currency);
        if ($plan === null || $period === null || $price === null || $plan->isFree()) {
            return $this->registerFailure($subscription, $this->plans->find($subscription->planId) ?? $this->plans->free(), 'У тарифа нет цены на этот срок.', false);
        }

        // A charge that the provider has not finished yet is waited for, not repeated.
        $open = $this->invoices->latestOpen($subscription->workspaceId, $now);
        if ($open !== null) {
            foreach ($this->payments->forInvoice($open->id) as $existing) {
                if ($existing->status === PaymentStatus::Pending && $existing->providerPaymentId !== null && $existing->createdAt > $now->modify('-1 hour')) {
                    $this->subscriptions->update($subscription->id, ['next_renewal_attempt_at' => DbTime::format($now->modify('+30 minutes'))]);

                    return 'waiting';
                }
            }
        }

        $method = $subscription->paymentMethodId === null ? null : $this->methods->find($subscription->paymentMethodId);
        $gateway = $method === null || !$method->active ? null : $this->gateways->forWebhook($method->provider);
        if ($method === null || !$method->active || $gateway === null) {
            return $this->registerFailure($subscription, $plan, 'Нет сохранённой карты для автоматического списания.', true);
        }
        $owner = $this->users->find($this->workspaces->findById($subscription->workspaceId)->ownerId ?? 0);
        if ($owner === null || $owner->email === null || $owner->email === '') {
            return $this->registerFailure($subscription, $plan, 'У владельца нет почты для чека.', false);
        }

        $start = $end > $now ? $end : $now;
        [$invoice, $payment] = $this->db->transaction(function () use ($subscription, $plan, $period, $price, $owner, $method, $start): array {
            $this->invoices->voidOpen($subscription->workspaceId);
            $invoice = $this->invoices->create(
                $subscription->workspaceId,
                $subscription->id,
                $plan->id,
                $period,
                InvoiceKind::Renewal,
                $price,
                $price,
                $subscription->currency,
                'Продление тарифа «' . $plan->name . '» ' . $period->forLabel(),
                $owner->email,
                $start,
                $period->addTo($start),
                $this->clock->now()->modify(sprintf('+%d hours', $this->config->int('billing.checkout_ttl_hours', 24))),
            );

            return [$invoice, $this->payments->create($invoice, $method->provider, false, $method->id)];
        });

        try {
            $charged = $gateway->chargeSaved($invoice, $payment, $method);
        } catch (GatewayException $e) {
            $this->logger->error('billing.charge_failed', ['payment' => $payment->publicId, 'provider' => $method->provider, 'error' => $e->getMessage(), 'retryable' => $e->retryable]);
            $this->payments->setStatus($payment->id, PaymentStatus::Failed, null, 'Списание не выполнено.');
            if ($e->retryable) {
                // The provider did not answer: this is not the customer's fault, so the attempt is not counted. Try again in an hour.
                $this->subscriptions->update($subscription->id, ['next_renewal_attempt_at' => DbTime::format($now->modify('+1 hour'))]);

                return 'waiting';
            }

            return $this->registerFailure($subscription, $plan, 'Платёжная система отказала в списании.', false);
        }
        $this->payments->attach($payment->id, $charged->providerPaymentId, $charged->providerStatus, null);

        $outcome = $this->billing->apply($this->payments->findById($payment->id) ?? $payment, $charged);

        return match ($outcome) {
            PaymentOutcome::Settled, PaymentOutcome::AlreadySettled => 'paid',
            PaymentOutcome::Failed => $this->registerFailure($subscription, $plan, 'Банк отклонил списание (' . ($charged->failureReason ?? 'без причины') . ').', false),
            default => $this->waitFor($subscription),
        };
    }

    private function waitFor(Subscription $subscription): string
    {
        $this->subscriptions->update($subscription->id, ['next_renewal_attempt_at' => DbTime::format($this->clock->now()->modify('+30 minutes'))]);

        return 'waiting';
    }

    /**
     * Count a failed attempt, plan the next one (or none) and tell the owner.
     */
    private function registerFailure(Subscription $subscription, Plan $plan, string $reason, bool $noMethod): string
    {
        $now = $this->clock->now();
        $attempts = $subscription->renewalAttempts + 1;
        $first = $subscription->firstAttemptAt ?? $now;
        $ladder = array_values(array_filter(array_map('intval', $this->config->array('billing.renewal.retry_days')), static fn (int $d): bool => $d > 0));
        $next = $attempts - 1 < count($ladder) ? $first->modify(sprintf('+%d days', $ladder[$attempts - 1])) : null;
        $this->subscriptions->update($subscription->id, [
            'renewal_attempts' => $attempts,
            'first_attempt_at' => DbTime::format($first),
            'next_renewal_attempt_at' => $next === null ? null : DbTime::format($next < $now ? $now->modify('+1 hour') : $next),
            'last_failure' => mb_substr($reason, 0, 255),
        ]);
        $this->audit->record('billing.renewal_failed', null, 'subscription', $subscription->publicId, ['attempt' => $attempts, 'reason' => $reason], $subscription->workspaceId);
        $this->mailer->renewalFailed($subscription->workspaceId, $plan, $next, $subscription->currentPeriodEnd ?? $now, $this->config->int('billing.renewal.grace_days', 3), $noMethod);

        return 'failed';
    }

    private function guard(Subscription $subscription, callable $work): void
    {
        try {
            $work();
        } catch (\Throwable $e) {
            $this->logger->error('billing.tick_failed', ['subscription' => $subscription->publicId, 'error' => $e->getMessage()]);
            // Do not hammer a broken row every minute.
            $this->subscriptions->update($subscription->id, ['next_renewal_attempt_at' => $subscription->nextRenewalAttemptAt === null ? null : DbTime::format($this->clock->now()->modify('+1 hour'))]);
        }
    }
}
