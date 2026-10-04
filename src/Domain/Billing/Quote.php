<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use DateTimeImmutable;

/**
 * What a plan change costs and what it does. `$immediate` false means nothing is charged now: the change is booked for the end
 * of the paid period (a downgrade), and `$amount` is 0.
 */
final class Quote
{
    public function __construct(
        public readonly InvoiceKind $kind,
        public readonly Plan $plan,
        public readonly BillingPeriod $period,
        public readonly int $amount,
        public readonly int $listPrice,
        public readonly string $currency,
        public readonly DateTimeImmutable $periodStart,
        public readonly DateTimeImmutable $periodEnd,
        public readonly string $description,
        public readonly bool $immediate = true,
    ) {
    }
}
