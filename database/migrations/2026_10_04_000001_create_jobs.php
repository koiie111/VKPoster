<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Migration;

return new class () implements Migration {
    public function up(Connection $db): void
    {
        $db->execute('CREATE TABLE jobs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            queue VARCHAR(64) NOT NULL DEFAULT \'default\',
            payload_json JSON NOT NULL,
            available_at DATETIME(6) NOT NULL,
            reserved_at DATETIME(6) NULL,
            reserved_by VARCHAR(64) NULL,
            attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 5,
            last_error TEXT NULL,
            created_at DATETIME(6) NOT NULL,
            KEY idx_jobs_pick (queue, reserved_at, available_at, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $db->execute('CREATE TABLE failed_jobs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            queue VARCHAR(64) NOT NULL,
            payload_json JSON NOT NULL,
            attempts SMALLINT UNSIGNED NOT NULL,
            error TEXT NOT NULL,
            failed_at DATETIME(6) NOT NULL,
            KEY idx_failed_jobs_failed_at (failed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    public function down(Connection $db): void
    {
        $db->execute('DROP TABLE IF EXISTS failed_jobs');
        $db->execute('DROP TABLE IF EXISTS jobs');
    }
};
