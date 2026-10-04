<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Migration;

return new class () implements Migration {
    public function up(Connection $db): void
    {
        // Journal of sign-in attempts against existing accounts (shown on the security page).
        // Throttling counters live in Redis, not here.
        $db->execute('CREATE TABLE login_attempts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            outcome VARCHAR(24) NOT NULL,
            ip VARCHAR(45) NOT NULL,
            user_agent VARCHAR(255) NOT NULL DEFAULT \'\',
            created_at DATETIME(6) NOT NULL,
            KEY idx_login_attempts_user (user_id, created_at),
            CONSTRAINT fk_login_attempts_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    public function down(Connection $db): void
    {
        $db->execute('DROP TABLE IF EXISTS login_attempts');
    }
};
