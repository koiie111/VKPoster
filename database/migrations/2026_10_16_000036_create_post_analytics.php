<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Migration;

return new class () implements Migration {
    public function up(Connection $db): void
    {
        $suffix = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $db->execute('CREATE TABLE analytics_tasks (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, workspace_id BIGINT UNSIGNED NOT NULL, channel_id BIGINT UNSIGNED NOT NULL, publication_id BIGINT UNSIGNED NULL, task_key VARCHAR(100) NOT NULL, due_at DATETIME(6) NOT NULL, completed_at DATETIME(6) NULL, enqueued_at DATETIME(6) NULL, error_code VARCHAR(32) NULL, lease_token CHAR(32) NULL, lease_until DATETIME(6) NULL, UNIQUE KEY analytics_once(workspace_id,task_key), KEY analytics_due(due_at,completed_at), FOREIGN KEY(workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE, FOREIGN KEY(channel_id) REFERENCES channels(id) ON DELETE CASCADE, FOREIGN KEY(publication_id) REFERENCES publications(id) ON DELETE CASCADE)' . $suffix);
        $db->execute('CREATE TABLE post_stats_snapshots (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, workspace_id BIGINT UNSIGNED NOT NULL, channel_id BIGINT UNSIGNED NOT NULL, publication_id BIGINT UNSIGNED NOT NULL, task_id BIGINT UNSIGNED NOT NULL UNIQUE, observed_at DATETIME(6) NOT NULL, metrics_json JSON NOT NULL, KEY post_stats_scope(workspace_id,publication_id,observed_at), FOREIGN KEY(workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE, FOREIGN KEY(channel_id) REFERENCES channels(id) ON DELETE CASCADE, FOREIGN KEY(publication_id) REFERENCES publications(id) ON DELETE CASCADE, FOREIGN KEY(task_id) REFERENCES analytics_tasks(id) ON DELETE CASCADE)' . $suffix);
        $db->execute('CREATE TABLE channel_stats_snapshots (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, workspace_id BIGINT UNSIGNED NOT NULL, channel_id BIGINT UNSIGNED NOT NULL, task_id BIGINT UNSIGNED NOT NULL UNIQUE, observed_at DATETIME(6) NOT NULL, followers BIGINT UNSIGNED NULL, KEY channel_stats_scope(workspace_id,channel_id,observed_at), FOREIGN KEY(workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE, FOREIGN KEY(channel_id) REFERENCES channels(id) ON DELETE CASCADE, FOREIGN KEY(task_id) REFERENCES analytics_tasks(id) ON DELETE CASCADE)' . $suffix);
        $db->execute('CREATE TABLE telegram_stats_reactions (workspace_id BIGINT UNSIGNED NOT NULL, channel_id BIGINT UNSIGNED NOT NULL, message_id VARCHAR(64) NOT NULL, event_date BIGINT UNSIGNED NOT NULL, reactions BIGINT UNSIGNED NOT NULL, PRIMARY KEY(workspace_id,channel_id,message_id), FOREIGN KEY(workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE, FOREIGN KEY(channel_id) REFERENCES channels(id) ON DELETE CASCADE)' . $suffix);
    }
    public function down(Connection $db): void
    {
        foreach (['telegram_stats_reactions','channel_stats_snapshots','post_stats_snapshots','analytics_tasks'] as $table) {
            $db->execute('DROP TABLE IF EXISTS ' . $table);
        }
    }
};
