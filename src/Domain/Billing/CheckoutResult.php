<?php

declare(strict_types=1);

namespace App\Domain\Billing;

/**
 * What the customer is sent to after choosing a plan: the provider's payment page, or (for a change that costs nothing now) a plain message.
 */
final class CheckoutResult
{
    private function __construct(public readonly ?string $redirectUrl, public readonly ?string $message, public readonly ?string $paymentId)
    {
    }

    public static function redirect(string $url, string $paymentId): self
    {
        return new self($url, null, $paymentId);
    }

    public static function done(string $message): self
    {
        return new self(null, $message, null);
    }
}
