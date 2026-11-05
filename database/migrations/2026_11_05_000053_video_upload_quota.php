<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Migration;

return new class () implements Migration {
    public function up(Connection $db): void
    {
        $db->execute('CREATE TABLE video_upload_quota_days (
            platform VARCHAR(16) NOT NULL,
            quota_day DATE NOT NULL,
            used INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (platform, quota_day)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $db->execute('CREATE TABLE video_upload_quota_reservations (
            publication_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            platform VARCHAR(16) NOT NULL,
            quota_day DATE NOT NULL,
            created_at DATETIME(6) NOT NULL,
            KEY idx_video_quota_day (platform, quota_day),
            CONSTRAINT fk_video_quota_publication FOREIGN KEY (publication_id) REFERENCES publications (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    public function down(Connection $db): void
    {
        $db->execute('DROP TABLE IF EXISTS video_upload_quota_reservations');
        $db->execute('DROP TABLE IF EXISTS video_upload_quota_days');
    }
};
