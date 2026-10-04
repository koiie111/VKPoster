<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Migration;

return new class () implements Migration {
    public function up(Connection $db): void
    {
        // Only SHA-256 hashes of tokens are stored. `selector` is set for `remember` tokens
        // (cookie value is `selector:validator`, the validator is hashed and rotated on every use).
        $db->execute('CREATE TABLE auth_tokens (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            type VARCHAR(24) NOT NULL,
            selector CHAR(24) NULL,
            token_hash CHAR(64) NOT NULL,
            payload_json JSON NULL,
            expires_at DATETIME(6) NOT NULL,
            used_at DATETIME(6) NULL,
            created_at DATETIME(6) NOT NULL,
            UNIQUE KEY uq_auth_tokens_hash (token_hash),
            UNIQUE KEY uq_auth_tokens_selector (selector),
            KEY idx_auth_tokens_user_type (user_id, type),
            CONSTRAINT fk_auth_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    public function down(Connection $db): void
    {
        $db->execute('DROP TABLE IF EXISTS auth_tokens');
    }
};
