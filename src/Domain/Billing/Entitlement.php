<?php

declare(strict_types=1);

namespace App\Domain\Billing;

/**
 * What one workspace may do right now: the plan that applies and the subscription behind it (null for a workspace that never had one).
 */
final class Entitlement
{
    /**
     * @param array<string, int> $extra what bought add-ons add to a limit (channels, members, storage_bytes); an unlimited limit stays unlimited
     */
    public function __construct(public readonly Plan $plan, public readonly ?Subscription $subscription, private readonly array $extra = [])
    {
    }

    public function limit(string $key): ?int
    {
        $base = $this->plan->limit($key);

        return $base === null ? null : $base + ($this->extra[$key] ?? 0);
    }

    public function hasFeature(string $feature): bool
    {
        return $this->plan->hasFeature($feature);
    }
}
