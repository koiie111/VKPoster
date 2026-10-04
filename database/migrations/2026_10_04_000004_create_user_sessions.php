<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Migration;

return new class () implements Migration {
    public function up(Connection $db): void
    {
        // session_id_hash = SHA-256 of the session id = the Redis key suffix, so a row can end its session.
        // public_id (ULID) is what appears in URLs ("sign out this device").
        $db->execute('CREATE TABLE user_sessions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            public_id CHAR(26) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            session_id_hash CHAR(64) NOT NULL,
            ip VARCHAR(45) NOT NULL,
            user_agent VARCHAR(255) NOT NULL DEFAULT \'\',
            created_at DATETIME(6) NOT NULL,
            last_seen_at DATETIME(6) NOT NULL,
            revoked_at DATETIME(6) NULL,
            UNIQUE KEY uq_user_sessions_public (public_id),
            UNIQUE KEY uq_user_sessions_hash (session_id_hash),
            KEY idx_user_sessions_user (user_id, revoked_at),
            CONSTRAINT fk_user_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    public function down(Connection $db): void
    {
        $db->execute('DROP TABLE IF EXISTS user_sessions');
    }
};
