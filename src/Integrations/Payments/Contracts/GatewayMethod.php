<?php

declare(strict_types=1);

namespace App\Integrations\Payments\Contracts;

/**
 * A reusable way to pay, as the provider names it: the reference for the next charge plus a caption such as "Visa •• 4242".
 */
final class GatewayMethod
{
    public function __construct(
        public readonly string $providerMethodId,
        public readonly string $title,
        public readonly ?string $customerKey = null,
    ) {
    }
}
