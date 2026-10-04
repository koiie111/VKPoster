<?php

declare(strict_types=1);

namespace App\Integrations\Payments;

use App\Integrations\Payments\Contracts\PaymentGateway;

/**
 * The gateways that may be offered or used: switched on in `BILLING_GATEWAYS`, with their credentials in place. The fake provider
 * (`fake`) is only ever available outside production, even if someone lists it.
 */
final class GatewayRegistry
{
    /**
     * @param list<PaymentGateway> $all every implementation
     * @param list<string> $enabled names from the configuration
     */
    public function __construct(private readonly array $all, private readonly array $enabled, private readonly bool $allowFake)
    {
    }

    /**
     * @return list<PaymentGateway> in the order of the configuration
     */
    public function available(): array
    {
        $out = [];
        foreach ($this->enabled as $name) {
            $gateway = $this->byName($name);
            if ($gateway !== null) {
                $out[] = $gateway;
            }
        }
        if ($this->allowFake && !in_array('fake', $this->enabled, true)) {
            $fake = $this->byName('fake');
            if ($fake !== null) {
                $out[] = $fake;
            }
        }

        return $out;
    }

    public function get(string $name): ?PaymentGateway
    {
        foreach ($this->available() as $gateway) {
            if ($gateway->name() === $name) {
                return $gateway;
            }
        }

        return null;
    }

    /**
     * A gateway for notifications and for charging old payments: usable even when it is no longer offered at checkout, as long as it is
     * configured (a payment started yesterday must still be able to finish).
     */
    public function forWebhook(string $name): ?PaymentGateway
    {
        $gateway = $this->byName($name);

        return $gateway !== null && ($name !== 'fake' || $this->allowFake) ? $gateway : null;
    }

    private function byName(string $name): ?PaymentGateway
    {
        foreach ($this->all as $gateway) {
            if ($gateway->name() === $name && $gateway->isConfigured() && ($name !== 'fake' || $this->allowFake)) {
                return $gateway;
            }
        }

        return null;
    }
}
