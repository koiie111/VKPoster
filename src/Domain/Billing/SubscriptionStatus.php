<?php

declare(strict_types=1);

namespace App\Domain\Billing;

/**
 * Where a subscription stands. `Trialing`: the free trial of a paid plan. `Active`: the paid period is running (or the plan is Free).
 * `PastDue`: the period is over or a renewal payment failed; the plan still works until the grace period ends.
 */
enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';

    public function label(): string
    {
        return match ($this) {
            self::Trialing => 'Пробный период',
            self::Active => 'Активна',
            self::PastDue => 'Ждём оплату',
        };
    }
}
