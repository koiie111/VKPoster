<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use DateTimeImmutable;

/**
 * A payment method the customer let us reuse for renewals: a reference held by the provider (YooKassa's `payment_method_id`, T-Bank's
 * `RebillId`) and a caption such as "Visa •• 4242". The card number itself never reaches our servers.
 */
final class PaymentMethod
{
    public function __construct(
        public readonly int $id,
        public readonly string $publicId,
        public readonly int $workspaceId,
        public readonly string $provider,
        public readonly string $providerMethodId,
        public readonly ?string $customerKey,
        public readonly string $title,
        public readonly bool $active,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }
}
