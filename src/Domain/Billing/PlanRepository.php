<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Kernel\Database\Connection;

/**
 * Reads the price list. Not cached on purpose: prices and limits are editable in the admin area, and the worker process lives for days.
 */
final class PlanRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return list<Plan> every plan, cheapest first
     */
    public function all(): array
    {
        $prices = [];
        foreach ($this->db->select('SELECT plan_id, period, currency, amount FROM plan_prices') as $row) {
            $prices[(int) $row['plan_id']][(string) $row['period']][(string) $row['currency']] = (int) $row['amount'];
        }
        $plans = [];
        foreach ($this->db->select('SELECT * FROM plans ORDER BY sort ASC, id ASC') as $row) {
            $plans[] = self::hydrate($row, $prices[(int) $row['id']] ?? []);
        }

        return $plans;
    }

    public function find(int $id): ?Plan
    {
        foreach ($this->all() as $plan) {
            if ($plan->id === $id) {
                return $plan;
            }
        }

        return null;
    }

    public function findByCode(string $code): ?Plan
    {
        foreach ($this->all() as $plan) {
            if ($plan->code === $code) {
                return $plan;
            }
        }

        return null;
    }

    /**
     * The Free plan always exists (the migration creates it); this is the fallback for every workspace without a subscription.
     */
    public function free(): Plan
    {
        return $this->findByCode(Plan::FREE) ?? throw new \LogicException('The Free plan is missing from the plans table.');
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, array<string, int>> $prices
     */
    private static function hydrate(array $row, array $prices): Plan
    {
        $limits = [];
        $decoded = json_decode((string) $row['limits_json'], true);
        foreach (is_array($decoded) ? $decoded : [] as $key => $value) {
            $limits[(string) $key] = is_int($value) ? $value : null;
        }
        $features = json_decode((string) $row['features_json'], true);

        return new Plan(
            (int) $row['id'],
            (string) $row['code'],
            (string) $row['name'],
            (int) $row['sort'],
            $limits,
            is_array($features) ? array_values(array_filter($features, 'is_string')) : [],
            $prices,
            (int) $row['is_public'] === 1,
        );
    }
}
