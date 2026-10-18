<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Migration;

return new class () implements Migration {
    public function up(Connection $db): void
    {
        // Workspace-wide link settings: the UTM rule (JSON) and whether links in posts are shortened.
        $db->execute('CREATE TABLE link_workspace_settings (
            workspace_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            utm_json TEXT NULL,
            shorten TINYINT(1) NOT NULL DEFAULT 0,
            updated_at DATETIME(6) NOT NULL,
            CONSTRAINT fk_link_ws_settings_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        // A channel can switch the workspace UTM rule off or replace it.
        $db->execute('CREATE TABLE link_channel_rules (
            channel_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            workspace_id BIGINT UNSIGNED NOT NULL,
            rule_json TEXT NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            KEY idx_link_channel_rules_workspace (workspace_id),
            CONSTRAINT fk_link_channel_rules_channel FOREIGN KEY (channel_id) REFERENCES channels (id) ON DELETE CASCADE,
            CONSTRAINT fk_link_channel_rules_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        // One short link per (post variant, target address): a retried publication reuses the code, and every network gets its own,
        // so the click count says where people came from. `variant_id` is null for links made by hand.
        $db->execute('CREATE TABLE short_links (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            code CHAR(7) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            workspace_id BIGINT UNSIGNED NOT NULL,
            variant_id BIGINT UNSIGNED NULL,
            platform VARCHAR(20) NOT NULL DEFAULT \'\',
            target_url VARCHAR(2000) NOT NULL,
            target_hash CHAR(64) NOT NULL,
            clicks BIGINT UNSIGNED NOT NULL DEFAULT 0,
            human_clicks BIGINT UNSIGNED NOT NULL DEFAULT 0,
            last_click_at DATETIME(6) NULL,
            disabled_at DATETIME(6) NULL,
            disabled_reason VARCHAR(200) NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME(6) NOT NULL,
            UNIQUE KEY uq_short_links_public (public_id),
            UNIQUE KEY uq_short_links_code (code),
            UNIQUE KEY uq_short_links_variant_target (variant_id, target_hash),
            KEY idx_short_links_workspace (workspace_id, id),
            CONSTRAINT fk_short_links_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE,
            CONSTRAINT fk_short_links_variant FOREIGN KEY (variant_id) REFERENCES post_variants (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        // A click keeps the time and a salted, truncated hash of the visitor (never the address itself); `is_bot` marks link
        // previews and crawlers, which are not people.
        $db->execute('CREATE TABLE short_link_clicks (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            link_id BIGINT UNSIGNED NOT NULL,
            clicked_at DATETIME(6) NOT NULL,
            visitor_hash CHAR(16) NOT NULL,
            is_bot TINYINT(1) NOT NULL DEFAULT 0,
            KEY idx_short_link_clicks_link (link_id, clicked_at),
            KEY idx_short_link_clicks_time (clicked_at),
            CONSTRAINT fk_short_link_clicks_link FOREIGN KEY (link_id) REFERENCES short_links (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    public function down(Connection $db): void
    {
        foreach (['short_link_clicks', 'short_links', 'link_channel_rules', 'link_workspace_settings'] as $table) {
            $db->execute('DROP TABLE ' . $table);
        }
    }
};
