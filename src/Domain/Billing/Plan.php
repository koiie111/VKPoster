<?php

declare(strict_types=1);

namespace App\Domain\Billing;

/**
 * A tariff: what it allows (`limits`, null = unlimited), which features it switches on and what it costs per period and currency.
 * Limit keys: channels, posts_per_month, workspaces, members, storage_bytes, crosspost_rules, analytics_days, ai_credits.
 */
final class Plan
{
    public const FREE = 'free';

    /**
     * @param array<string, int|null> $limits
     * @param list<string> $features
     * @param array<string, array<string, int>> $prices period => currency => minor units
     */
    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public readonly string $name,
        public readonly int $sort,
        public readonly array $limits,
        public readonly array $features,
        public readonly array $prices,
        public readonly bool $isPublic,
    ) {
    }

    public function isFree(): bool
    {
        return $this->code === self::FREE;
    }

    /**
     * A missing limit counts as zero (nothing allowed), a null one as unlimited.
     */
    public function limit(string $key): ?int
    {
        return array_key_exists($key, $this->limits) ? $this->limits[$key] : 0;
    }

    public function hasFeature(string $feature): bool
    {
        return in_array($feature, $this->features, true);
    }

    public function priceFor(BillingPeriod $period, string $currency = 'RUB'): ?int
    {
        return $this->prices[$period->value][$currency] ?? null;
    }

    /** Price of one month, whatever the period: to tell an upgrade from a downgrade. */
    public function monthlyEquivalent(BillingPeriod $period, string $currency = 'RUB'): ?int
    {
        $price = $this->priceFor($period, $currency);

        return $price === null ? null : intdiv($price, $period->months());
    }
}
