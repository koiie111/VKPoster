<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Kernel\Database\Connection;

/**
 * Copies the starting price list from `config/billing.php` into the database. It only adds what is missing and never overwrites:
 * once a plan is in the table, its prices and limits belong to the owner (changed from the admin area, without a deploy).
 */
final class PlanCatalog
{
    /**
     * @param array<string, array<string, mixed>> $catalog code => definition (see config/billing.php)
     * @return int how many plans were added
     */
    public static function insertMissing(Connection $db, array $catalog, string $currency): int
    {
        $added = 0;
        $now = gmdate('Y-m-d H:i:s.u');
        foreach ($catalog as $code => $definition) {
            $existing = $db->table('plans')->where('code', '=', $code)->first();
            if ($existing !== null) {
                $planId = (int) $existing['id'];
            } else {
                $planId = (int) $db->table('plans')->insert([
                    'code' => $code,
                    'name' => (string) ($definition['name'] ?? $code),
                    'sort' => (int) ($definition['sort'] ?? 0),
                    'limits_json' => json_encode($definition['limits'] ?? [], JSON_THROW_ON_ERROR),
                    'features_json' => json_encode(array_values((array) ($definition['features'] ?? [])), JSON_THROW_ON_ERROR),
                    'is_public' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                ++$added;
            }
            foreach ((array) ($definition['prices'] ?? []) as $period => $amount) {
                $has = $db->table('plan_prices')->where('plan_id', '=', $planId)->where('period', '=', (string) $period)->where('currency', '=', $currency)->exists();
                if (!$has) {
                    $db->table('plan_prices')->insert(['plan_id' => $planId, 'period' => (string) $period, 'currency' => $currency, 'amount' => (int) $amount]);
                }
            }
        }

        return $added;
    }
}
