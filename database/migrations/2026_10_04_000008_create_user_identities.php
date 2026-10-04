<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Migration;

return new class () implements Migration {
    public function up(Connection $db): void
    {
        // One identity per provider per user keeps the "sign-in methods" screen simple; the same provider
        // account can never belong to two users. `email` is what the provider reported (informational only).
        $db->execute('CREATE TABLE user_identities (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            provider VARCHAR(16) NOT NULL,
            provider_user_id VARCHAR(191) NOT NULL,
            email VARCHAR(254) NULL,
            display_name VARCHAR(150) NULL,
            linked_at DATETIME(6) NOT NULL,
            last_login_at DATETIME(6) NULL,
            UNIQUE KEY uq_identity_provider_account (provider, provider_user_id),
            UNIQUE KEY uq_identity_user_provider (user_id, provider),
            CONSTRAINT fk_identity_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    public function down(Connection $db): void
    {
        $db->execute('DROP TABLE IF EXISTS user_identities');
    }
};
