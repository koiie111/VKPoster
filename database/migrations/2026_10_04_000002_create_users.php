<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Migration;

return new class () implements Migration {
    public function up(Connection $db): void
    {
        // email is NULL-able for accounts created through social login (stage 03).
        // totp_last_step is the last accepted 30-second TOTP step: a code is never accepted twice.
        $db->execute('CREATE TABLE users (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(254) NULL,
            email_verified_at DATETIME(6) NULL,
            password_hash VARCHAR(255) NULL,
            password_changed_at DATETIME(6) NULL,
            name VARCHAR(100) NOT NULL,
            locale VARCHAR(10) NOT NULL DEFAULT \'ru\',
            timezone VARCHAR(64) NOT NULL DEFAULT \'Europe/Moscow\',
            totp_secret_enc TEXT NULL,
            totp_enabled_at DATETIME(6) NULL,
            totp_last_step BIGINT UNSIGNED NULL,
            is_superadmin TINYINT(1) NOT NULL DEFAULT 0,
            status VARCHAR(16) NOT NULL DEFAULT \'active\',
            consent_version VARCHAR(32) NULL,
            consent_at DATETIME(6) NULL,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            UNIQUE KEY uq_users_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    public function down(Connection $db): void
    {
        $db->execute('DROP TABLE IF EXISTS users');
    }
};
