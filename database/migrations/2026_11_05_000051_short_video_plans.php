<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Migration;

return new class () implements Migration {
    private const LIMITS = [
        'free' => [2, 250],
        'start' => [10, 500],
        'pro' => [60, 1024],
        'agency' => [300, 1024],
    ];

    public function up(Connection $db): void
    {
        foreach ($db->select('SELECT id, code, limits_json, features_json FROM plans') as $row) {
            $defaults = self::LIMITS[(string) $row['code']] ?? [0, 0];
            $limits = json_decode((string) $row['limits_json'], true, 32, JSON_THROW_ON_ERROR);
            $features = json_decode((string) $row['features_json'], true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($limits) || !is_array($features)) {
                continue;
            }
            $limits['short_videos_per_month'] ??= $defaults[0];
            $limits['short_video_file_mib'] ??= $defaults[1];
            if (isset(self::LIMITS[(string) $row['code']]) && !in_array('short_video', $features, true)) {
                $features[] = 'short_video';
            }
            $db->execute('UPDATE plans SET limits_json=?, features_json=? WHERE id=?', [json_encode($limits, JSON_THROW_ON_ERROR), json_encode($features, JSON_THROW_ON_ERROR), $row['id']]);
        }
    }

    public function down(Connection $db): void
    {
        foreach ($db->select('SELECT id, limits_json, features_json FROM plans') as $row) {
            $limits = json_decode((string) $row['limits_json'], true, 32, JSON_THROW_ON_ERROR);
            $features = json_decode((string) $row['features_json'], true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($limits) || !is_array($features)) {
                continue;
            }
            unset($limits['short_videos_per_month'], $limits['short_video_file_mib']);
            $features = array_values(array_filter($features, static fn (mixed $feature): bool => $feature !== 'short_video'));
            $db->execute('UPDATE plans SET limits_json=?, features_json=? WHERE id=?', [json_encode($limits, JSON_THROW_ON_ERROR), json_encode($features, JSON_THROW_ON_ERROR), $row['id']]);
        }
    }
};
