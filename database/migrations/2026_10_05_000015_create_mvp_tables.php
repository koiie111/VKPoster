<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Migration;

return new class () implements Migration {
    public function up(Connection $db): void
    {
        // Settings the owner changes without a deploy (platform switches, the status notice). `value_json` is any JSON value.
        $db->execute('CREATE TABLE app_settings (
            name VARCHAR(64) NOT NULL PRIMARY KEY,
            value_json TEXT NOT NULL,
            updated_by BIGINT UNSIGNED NULL,
            updated_at DATETIME(6) NOT NULL,
            CONSTRAINT fk_app_settings_user FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        // Proof of consent (152-FZ): one row per acceptance of a version of the legal documents, kept after the version changes.
        $db->execute('CREATE TABLE user_consents (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            version VARCHAR(32) NOT NULL,
            ip VARCHAR(45) NULL,
            accepted_at DATETIME(6) NOT NULL,
            KEY idx_user_consents_user (user_id, accepted_at),
            CONSTRAINT fk_user_consents_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    public function down(Connection $db): void
    {
        $db->execute('DROP TABLE IF EXISTS user_consents');
        $db->execute('DROP TABLE IF EXISTS app_settings');
    }
};
