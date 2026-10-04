<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use DateTimeImmutable;

/**
 * One attempt to collect an invoice through one provider. `providerPaymentId` stays null until the provider has accepted the order.
 */
final class Payment
{
    public function __construct(
        public readonly int $id,
        public readonly string $publicId,
        public readonly int $invoiceId,
        public readonly int $workspaceId,
        public readonly string $provider,
        public readonly ?string $providerPaymentId,
        public readonly PaymentStatus $status,
        public readonly ?string $providerStatus,
        public readonly int $amount,
        public readonly string $currency,
        public readonly int $refundedAmount,
        public readonly ?int $paymentMethodId,
        public readonly bool $saveMethod,
        public readonly ?string $confirmationUrl,
        public readonly ?string $errorMessage,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }
}
