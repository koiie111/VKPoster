<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use DateTimeImmutable;

/**
 * What a workspace has: its plan, whether it is paid or on trial, until when, and what is going to happen at the end of the period.
 * The Free plan is a subscription without period dates. Pending plan and period are a downgrade chosen for the end of the paid period.
 */
final class Subscription
{
    public function __construct(
        public readonly int $id,
        public readonly string $publicId,
        public readonly int $workspaceId,
        public readonly int $planId,
        public readonly SubscriptionStatus $status,
        public readonly ?BillingPeriod $period,
        public readonly string $currency,
        public readonly int $priceAmount,
        public readonly ?DateTimeImmutable $currentPeriodStart,
        public readonly ?DateTimeImmutable $currentPeriodEnd,
        public readonly ?DateTimeImmutable $trialEndsAt,
        public readonly ?DateTimeImmutable $trialRemindedAt,
        public readonly bool $cancelAtPeriodEnd,
        public readonly ?int $pendingPlanId,
        public readonly ?BillingPeriod $pendingPeriod,
        public readonly ?int $paymentMethodId,
        public readonly int $renewalAttempts,
        public readonly ?DateTimeImmutable $firstAttemptAt,
        public readonly ?DateTimeImmutable $nextRenewalAttemptAt,
        public readonly ?string $lastFailure,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }

    public function isTrial(): bool
    {
        return $this->status === SubscriptionStatus::Trialing;
    }

    /** A paid period (not a trial, not Free) is on record. */
    public function hasPaidPeriod(): bool
    {
        return !$this->isTrial() && $this->currentPeriodEnd !== null && $this->period !== null;
    }

    /** The subscription will be charged again by itself. */
    public function autoRenews(): bool
    {
        return $this->hasPaidPeriod() && !$this->cancelAtPeriodEnd && $this->paymentMethodId !== null;
    }
}
