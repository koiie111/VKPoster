<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Migration;

/**
 * Stage 13: queues with slots, the evergreen pool, recurring rules, triggers, Telegram cross-posting, RSS feeds and bulk import.
 */
return new class () implements Migration {
    private const TAIL = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    public function up(Connection $db): void
    {
        $db->execute('CREATE TABLE queues (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, public_id CHAR(26) NOT NULL,
            workspace_id BIGINT UNSIGNED NOT NULL, name VARCHAR(100) NOT NULL, paused TINYINT(1) NOT NULL DEFAULT 0,
            created_by BIGINT UNSIGNED NULL, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL,
            UNIQUE KEY uq_queues_public (public_id), KEY idx_queues_workspace (workspace_id),
            FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
        )' . self::TAIL);
        $db->execute('CREATE TABLE queue_slots (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, queue_id BIGINT UNSIGNED NOT NULL,
            weekday TINYINT UNSIGNED NOT NULL, at_time CHAR(5) NOT NULL,
            UNIQUE KEY uq_queue_slot (queue_id, weekday, at_time),
            FOREIGN KEY (queue_id) REFERENCES queues(id) ON DELETE CASCADE
        )' . self::TAIL);
        $db->execute('CREATE TABLE queue_channels (
            queue_id BIGINT UNSIGNED NOT NULL, channel_id BIGINT UNSIGNED NOT NULL, PRIMARY KEY (queue_id, channel_id),
            FOREIGN KEY (queue_id) REFERENCES queues(id) ON DELETE CASCADE,
            FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE CASCADE
        )' . self::TAIL);
        $db->execute("CREATE TABLE queue_entries (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, queue_id BIGINT UNSIGNED NOT NULL, post_id BIGINT UNSIGNED NOT NULL,
            position INT NOT NULL, source VARCHAR(48) NOT NULL DEFAULT 'manual', note VARCHAR(255) NULL, created_at DATETIME(6) NOT NULL,
            UNIQUE KEY uq_queue_entry_post (post_id), KEY idx_queue_entries_order (queue_id, position),
            FOREIGN KEY (queue_id) REFERENCES queues(id) ON DELETE CASCADE,
            FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
        )" . self::TAIL);
        $db->execute('CREATE TABLE evergreen_pools (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, public_id CHAR(26) NOT NULL,
            workspace_id BIGINT UNSIGNED NOT NULL, queue_id BIGINT UNSIGNED NOT NULL, name VARCHAR(100) NOT NULL,
            min_interval_days INT UNSIGNED NOT NULL DEFAULT 30, keep_ahead INT UNSIGNED NOT NULL DEFAULT 3,
            enabled TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL,
            UNIQUE KEY uq_pools_public (public_id), KEY idx_pools_workspace (workspace_id),
            FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
            FOREIGN KEY (queue_id) REFERENCES queues(id) ON DELETE CASCADE
        )' . self::TAIL);
        $db->execute('CREATE TABLE evergreen_posts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, pool_id BIGINT UNSIGNED NOT NULL, post_id BIGINT UNSIGNED NOT NULL,
            last_used_at DATETIME(6) NULL, uses INT UNSIGNED NOT NULL DEFAULT 0, created_at DATETIME(6) NOT NULL,
            UNIQUE KEY uq_pool_post (pool_id, post_id),
            FOREIGN KEY (pool_id) REFERENCES evergreen_pools(id) ON DELETE CASCADE,
            FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
        )' . self::TAIL);

        $db->execute("CREATE TABLE recurring_rules (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, public_id CHAR(26) NOT NULL,
            workspace_id BIGINT UNSIGNED NOT NULL, post_id BIGINT UNSIGNED NOT NULL,
            frequency VARCHAR(16) NOT NULL, interval_days INT UNSIGNED NOT NULL DEFAULT 1, weekdays VARCHAR(16) NOT NULL DEFAULT '',
            month_day TINYINT UNSIGNED NULL, at_time CHAR(5) NOT NULL, timezone VARCHAR(64) NOT NULL,
            starts_on DATE NOT NULL, ends_on DATE NULL, max_count INT UNSIGNED NULL, done_count INT UNSIGNED NOT NULL DEFAULT 0,
            next_run_at DATETIME(6) NULL, enabled TINYINT(1) NOT NULL DEFAULT 1, last_error VARCHAR(255) NULL,
            created_by BIGINT UNSIGNED NULL, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL,
            UNIQUE KEY uq_recurring_public (public_id), KEY idx_recurring_due (enabled, next_run_at), KEY idx_recurring_workspace (workspace_id),
            FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
            FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
        )" . self::TAIL);
        $db->execute('CREATE TABLE recurring_runs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, rule_id BIGINT UNSIGNED NOT NULL, occurrence_at DATETIME(6) NOT NULL,
            post_id BIGINT UNSIGNED NULL, created_at DATETIME(6) NOT NULL,
            UNIQUE KEY uq_recurring_run (rule_id, occurrence_at),
            FOREIGN KEY (rule_id) REFERENCES recurring_rules(id) ON DELETE CASCADE,
            FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE SET NULL
        )' . self::TAIL);

        $db->execute("CREATE TABLE triggers (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, public_id CHAR(26) NOT NULL,
            workspace_id BIGINT UNSIGNED NOT NULL, post_id BIGINT UNSIGNED NULL, channel_id BIGINT UNSIGNED NULL, event_post_id BIGINT UNSIGNED NULL,
            event VARCHAR(16) NOT NULL, delay_minutes INT UNSIGNED NOT NULL DEFAULT 0, fire_at DATETIME(6) NULL,
            action VARCHAR(24) NOT NULL, params_json JSON NOT NULL, status VARCHAR(12) NOT NULL DEFAULT 'pending',
            fired_at DATETIME(6) NULL, result VARCHAR(255) NULL, created_by BIGINT UNSIGNED NULL, created_at DATETIME(6) NOT NULL,
            UNIQUE KEY uq_triggers_public (public_id), KEY idx_triggers_due (status, fire_at),
            KEY idx_triggers_post (post_id), KEY idx_triggers_channel (channel_id), KEY idx_triggers_workspace (workspace_id),
            FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
            FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
            FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE CASCADE
        )" . self::TAIL);

        $db->execute("CREATE TABLE crosspost_rules (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, public_id CHAR(26) NOT NULL,
            workspace_id BIGINT UNSIGNED NOT NULL, source_channel_id BIGINT UNSIGNED NOT NULL, name VARCHAR(100) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1, filters_json JSON NOT NULL, transforms_json JSON NOT NULL,
            delay_minutes INT UNSIGNED NOT NULL DEFAULT 0, mode VARCHAR(12) NOT NULL DEFAULT 'draft', sync_edits TINYINT(1) NOT NULL DEFAULT 0,
            last_run_at DATETIME(6) NULL, last_note VARCHAR(255) NULL, posts_created INT UNSIGNED NOT NULL DEFAULT 0,
            created_by BIGINT UNSIGNED NULL, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL,
            UNIQUE KEY uq_crosspost_public (public_id), KEY idx_crosspost_source (source_channel_id, enabled), KEY idx_crosspost_workspace (workspace_id),
            FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
            FOREIGN KEY (source_channel_id) REFERENCES channels(id) ON DELETE CASCADE
        )" . self::TAIL);
        $db->execute('CREATE TABLE crosspost_targets (
            rule_id BIGINT UNSIGNED NOT NULL, channel_id BIGINT UNSIGNED NOT NULL, PRIMARY KEY (rule_id, channel_id),
            FOREIGN KEY (rule_id) REFERENCES crosspost_rules(id) ON DELETE CASCADE,
            FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE CASCADE
        )' . self::TAIL);
        $db->execute('CREATE TABLE crosspost_buffer (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, chat_id BIGINT NOT NULL, message_id BIGINT NOT NULL,
            group_key VARCHAR(64) NOT NULL, payload_json JSON NOT NULL, taken_at DATETIME(6) NULL, created_at DATETIME(6) NOT NULL,
            UNIQUE KEY uq_crosspost_message (chat_id, message_id), KEY idx_crosspost_group (chat_id, group_key)
        )' . self::TAIL);
        $db->execute('CREATE TABLE crosspost_items (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, rule_id BIGINT UNSIGNED NOT NULL, message_id BIGINT NOT NULL,
            post_id BIGINT UNSIGNED NULL, created_at DATETIME(6) NOT NULL,
            UNIQUE KEY uq_crosspost_item (rule_id, message_id),
            FOREIGN KEY (rule_id) REFERENCES crosspost_rules(id) ON DELETE CASCADE,
            FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE SET NULL
        )' . self::TAIL);

        $db->execute("CREATE TABLE rss_feeds (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, public_id CHAR(26) NOT NULL,
            workspace_id BIGINT UNSIGNED NOT NULL, name VARCHAR(100) NOT NULL, url VARCHAR(2000) NOT NULL, template TEXT NOT NULL,
            mode VARCHAR(12) NOT NULL DEFAULT 'draft', queue_id BIGINT UNSIGNED NULL, interval_minutes INT UNSIGNED NOT NULL DEFAULT 60,
            enabled TINYINT(1) NOT NULL DEFAULT 1, etag VARCHAR(255) NULL, last_modified VARCHAR(64) NULL,
            last_checked_at DATETIME(6) NULL, next_check_at DATETIME(6) NULL, last_error VARCHAR(255) NULL, error_count INT UNSIGNED NOT NULL DEFAULT 0,
            created_by BIGINT UNSIGNED NULL, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL,
            UNIQUE KEY uq_rss_public (public_id), KEY idx_rss_due (enabled, next_check_at), KEY idx_rss_workspace (workspace_id),
            FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
            FOREIGN KEY (queue_id) REFERENCES queues(id) ON DELETE SET NULL
        )" . self::TAIL);
        $db->execute('CREATE TABLE rss_feed_channels (
            feed_id BIGINT UNSIGNED NOT NULL, channel_id BIGINT UNSIGNED NOT NULL, PRIMARY KEY (feed_id, channel_id),
            FOREIGN KEY (feed_id) REFERENCES rss_feeds(id) ON DELETE CASCADE,
            FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE CASCADE
        )' . self::TAIL);
        $db->execute('CREATE TABLE rss_items (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, feed_id BIGINT UNSIGNED NOT NULL, guid_hash CHAR(64) NOT NULL,
            post_id BIGINT UNSIGNED NULL, created_at DATETIME(6) NOT NULL,
            UNIQUE KEY uq_rss_item (feed_id, guid_hash),
            FOREIGN KEY (feed_id) REFERENCES rss_feeds(id) ON DELETE CASCADE,
            FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE SET NULL
        )' . self::TAIL);

        $db->execute("CREATE TABLE import_batches (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, public_id CHAR(26) NOT NULL,
            workspace_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL, filename VARCHAR(255) NOT NULL,
            mode VARCHAR(12) NOT NULL DEFAULT 'draft', status VARCHAR(12) NOT NULL DEFAULT 'preview',
            rows_json LONGTEXT NOT NULL, total INT UNSIGNED NOT NULL DEFAULT 0, ok_count INT UNSIGNED NOT NULL DEFAULT 0,
            error_count INT UNSIGNED NOT NULL DEFAULT 0, created_count INT UNSIGNED NOT NULL DEFAULT 0, failed_json LONGTEXT NULL,
            created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL,
            UNIQUE KEY uq_import_public (public_id), KEY idx_import_workspace (workspace_id),
            FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
        )" . self::TAIL);
    }

    public function down(Connection $db): void
    {
        foreach (['import_batches', 'rss_items', 'rss_feed_channels', 'rss_feeds', 'crosspost_items', 'crosspost_buffer', 'crosspost_targets', 'crosspost_rules', 'triggers', 'recurring_runs', 'recurring_rules', 'evergreen_posts', 'evergreen_pools', 'queue_entries', 'queue_channels', 'queue_slots', 'queues'] as $table) {
            $db->execute('DROP TABLE IF EXISTS ' . $table);
        }
    }
};
