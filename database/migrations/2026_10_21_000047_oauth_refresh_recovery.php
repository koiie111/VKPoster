<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Migration;

/** One-shot OAuth refresh fence and encrypted, per-attempt recovery envelope; no plaintext credentials. */
return new class () implements Migration {
    public function up(Connection $db): void
    {
        $db->execute("CREATE TABLE oauth_refresh_attempts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            credential_id BIGINT UNSIGNED NOT NULL,
            active_credential_id BIGINT UNSIGNED NULL UNIQUE,
            input_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            state VARCHAR(32) NOT NULL,
            recovery_key_enc TEXT NOT NULL,
            response_enc MEDIUMTEXT NULL,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            INDEX oauth_refresh_history (credential_id, id),
            CONSTRAINT oauth_refresh_credential FOREIGN KEY (credential_id) REFERENCES platform_credentials(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");
    }

    public function down(Connection $db): void
    {
        if ((int) ($db->select("SELECT COUNT(*) AS n FROM oauth_refresh_attempts WHERE state IN ('dispatched','response_saved','recovery_required')")[0]['n'] ?? 0) > 0) {
            throw new RuntimeException('Resolve pending OAuth recovery before rollback.');
        }
        $db->execute('DROP TABLE oauth_refresh_attempts');
    }
};
