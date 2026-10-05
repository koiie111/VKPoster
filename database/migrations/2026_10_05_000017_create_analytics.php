<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Migration;

return new class () implements Migration {
    public function up(Connection $db): void
    {
        $table = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        // The raw stream of business events (server side only, no third-party tracker). No foreign keys on purpose: events outlive the people
        // they are about. `once_key` makes "the first X of a workspace" safe to report twice (a second insert is ignored).
        $db->execute('CREATE TABLE analytics_events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(48) NOT NULL,
            user_id BIGINT UNSIGNED NULL,
            workspace_id BIGINT UNSIGNED NULL,
            visitor_id CHAR(26) NULL,
            once_key VARCHAR(100) NULL,
            props_json TEXT NULL,
            occurred_at DATETIME(6) NOT NULL,
            UNIQUE KEY uq_analytics_once (once_key),
            KEY idx_analytics_name (name, occurred_at),
            KEY idx_analytics_user (user_id, name),
            KEY idx_analytics_workspace (workspace_id, name)
        )' . $table);

        // One row per person and day they used the service (DAU/WAU/MAU are counted from here).
        $db->execute('CREATE TABLE user_activity_days (
            user_id BIGINT UNSIGNED NOT NULL,
            day DATE NOT NULL,
            PRIMARY KEY (user_id, day),
            KEY idx_activity_day (day)
        )' . $table);

        // Where a person came from on their first visit (UTM tags and the referring site), copied from the session at sign-up.
        $db->execute('CREATE TABLE user_attribution (
            user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            utm_source VARCHAR(80) NULL,
            utm_medium VARCHAR(80) NULL,
            utm_campaign VARCHAR(120) NULL,
            referrer VARCHAR(120) NULL,
            landing VARCHAR(200) NULL,
            visitor_id CHAR(26) NULL,
            first_seen_at DATETIME(6) NOT NULL,
            CONSTRAINT fk_attribution_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        )' . $table);

        // Daily aggregates that the dashboard reads. `dim` is the breakdown (`yookassa:pro:RUB`, a source, a currency, or empty); `value` is a
        // count or an amount in minor units (kopecks). Recomputed for the last days by the scheduler; idempotent.
        $db->execute('CREATE TABLE metrics_daily (
            day DATE NOT NULL,
            metric VARCHAR(40) NOT NULL,
            dim VARCHAR(80) NOT NULL DEFAULT \'\',
            value BIGINT NOT NULL,
            PRIMARY KEY (day, metric, dim),
            KEY idx_metrics_metric (metric, day)
        )' . $table);
    }

    public function down(Connection $db): void
    {
        foreach (['metrics_daily', 'user_attribution', 'user_activity_days', 'analytics_events'] as $name) {
            $db->execute('DROP TABLE IF EXISTS ' . $name);
        }
    }
};
