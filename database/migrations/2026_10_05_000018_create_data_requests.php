<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Migration;

return new class () implements Migration {
    public function up(Connection $db): void
    {
        // Requests about a person's data under 152-FZ (a copy of the data, or deletion). `user_id` stays NULL-able and has no foreign key:
        // the request, and the proof that it was answered, must outlive the person's account. `email` is what the person was called by then.
        $db->execute('CREATE TABLE data_requests (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            public_id CHAR(26) NOT NULL,
            user_id BIGINT UNSIGNED NULL,
            email VARCHAR(254) NULL,
            type VARCHAR(12) NOT NULL,
            status VARCHAR(12) NOT NULL DEFAULT \'open\',
            note VARCHAR(1000) NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME(6) NOT NULL,
            completed_by BIGINT UNSIGNED NULL,
            completed_at DATETIME(6) NULL,
            UNIQUE KEY uq_data_requests_public (public_id),
            KEY idx_data_requests_status (status, created_at),
            KEY idx_data_requests_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    public function down(Connection $db): void
    {
        $db->execute('DROP TABLE IF EXISTS data_requests');
    }
};
