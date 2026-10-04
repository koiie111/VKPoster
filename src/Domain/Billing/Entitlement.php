<?php

declare(strict_types=1);

namespace App\Domain\Billing;

/**
 * What one workspace may do right now: the plan that applies and the subscription behind it (null for a workspace that never had one).
 */
final class Entitlement
{
    public function __construct(public readonly Plan $plan, public readonly ?Subscription $subscription)
    {
    }

    public function limit(string $key): ?int
    {
        return $this->plan->limit($key);
    }

    public function hasFeature(string $feature): bool
    {
        return $this->plan->hasFeature($feature);
    }
}
