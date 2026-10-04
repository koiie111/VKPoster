<?php

declare(strict_types=1);

namespace App\Integrations\Payments\Contracts;

/**
 * Result of a refund request. `succeeded` false means the provider accepted it but has not finished (rare); the money has not moved yet.
 */
final class GatewayRefund
{
    public function __construct(
        public readonly string $refundId,
        public readonly bool $succeeded,
        public readonly int $amount,
    ) {
    }
}
