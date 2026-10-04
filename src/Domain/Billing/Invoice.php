<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use DateTimeImmutable;

/**
 * A bill for one paid period (or, for an upgrade, for the rest of the current one). `amount` is what is charged, `listPrice` the full
 * price of the plan for the period; they differ for a prorated upgrade. The customer's email is a snapshot for the receipt.
 */
final class Invoice
{
    public function __construct(
        public readonly int $id,
        public readonly string $publicId,
        public readonly string $number,
        public readonly int $workspaceId,
        public readonly ?int $subscriptionId,
        public readonly int $planId,
        public readonly BillingPeriod $period,
        public readonly InvoiceKind $kind,
        public readonly int $amount,
        public readonly int $listPrice,
        public readonly string $currency,
        public readonly InvoiceStatus $status,
        public readonly string $description,
        public readonly string $customerEmail,
        public readonly DateTimeImmutable $periodStart,
        public readonly DateTimeImmutable $periodEnd,
        public readonly DateTimeImmutable $createdAt,
        public readonly ?DateTimeImmutable $paidAt,
        public readonly DateTimeImmutable $expiresAt,
    ) {
    }
}
