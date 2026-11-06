<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Migration;

/** Adds source entitlements to existing plans without changing owner-managed prices or limits. */
return new class () implements Migration {
    public function up(Connection $db): void
    {
        foreach (['free' => 0, 'start' => 3, 'pro' => 15, 'agency' => 50] as $code => $allowance) {
            $row = $db->table('plans')->where('code', '=', $code)->first();
            if ($row === null) {
                continue;
            }
            $limits = json_decode((string) $row['limits_json'], true, 32, JSON_THROW_ON_ERROR);
            $features = json_decode((string) $row['features_json'], true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($limits) || !is_array($features)) {
                continue;
            }
            if (!array_key_exists('sources', $limits)) {
                $limits['sources'] = $allowance;
            }
            $newFeatures = $code === 'free' ? [] : ($code === 'start' ? ['sources'] : ['sources', 'radar', 'source_automation']);
            foreach ($newFeatures as $feature) {
                if (!in_array($feature, $features, true)) {
                    $features[] = $feature;
                }
            }
            $db->table('plans')->where('id', '=', (int) $row['id'])->update([
                'limits_json' => json_encode($limits, JSON_THROW_ON_ERROR),
                'features_json' => json_encode($features, JSON_THROW_ON_ERROR),
            ]);
        }
    }

    public function down(Connection $db): void
    {
        // Entitlements may have been edited by the owner after deployment; rollback must preserve them.
    }
};
