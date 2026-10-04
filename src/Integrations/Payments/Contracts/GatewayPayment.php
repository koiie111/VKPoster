<?php

declare(strict_types=1);

namespace App\Integrations\Payments\Contracts;

use App\Domain\Billing\PaymentStatus;

/**
 * A payment as the provider reports it (after creation, a charge or a status check). `amount` is in kopecks. `method` is present once
 * the provider has a reusable method for the customer (the card was saved).
 */
final class GatewayPayment
{
    public function __construct(
        public readonly string $providerPaymentId,
        public readonly PaymentStatus $status,
        public readonly string $providerStatus,
        public readonly int $amount,
        public readonly string $currency,
        public readonly ?string $confirmationUrl = null,
        public readonly ?GatewayMethod $method = null,
        public readonly ?string $failureReason = null,
    ) {
    }
}
