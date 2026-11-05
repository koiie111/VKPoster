<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Migration;

return new class () implements Migration {
    public function up(Connection $db): void
    {
        $db->execute('CREATE TABLE video_provider_sessions (
            publication_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            workspace_id BIGINT UNSIGNED NOT NULL,
            platform VARCHAR(16) NOT NULL,
            remote_id VARCHAR(255) NULL,
            upload_url_enc TEXT NULL,
            confirmed_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            phase VARCHAR(24) NOT NULL,
            poll_after DATETIME(6) NULL,
            deadline_at DATETIME(6) NOT NULL,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            KEY idx_video_sessions_poll (phase, poll_after),
            CONSTRAINT fk_video_session_publication FOREIGN KEY (publication_id) REFERENCES publications (id) ON DELETE CASCADE,
            CONSTRAINT fk_video_session_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    public function down(Connection $db): void
    {
        $db->execute('DROP TABLE IF EXISTS video_provider_sessions');
    }
};
