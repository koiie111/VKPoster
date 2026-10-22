<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Migration;

/** Public credential keys and expiring, session-bound one-use challenges; no authenticator secret is stored. */
return new class () implements Migration {
    public function up(Connection $db): void
    {
        $db->execute('CREATE TABLE webauthn_credentials (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, credential_id VARBINARY(1024) NOT NULL, credential_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE, public_key TEXT NOT NULL, sign_count BIGINT UNSIGNED NOT NULL DEFAULT 0, label VARCHAR(80) NOT NULL, created_at DATETIME(6) NOT NULL, last_used_at DATETIME(6) NULL, revoked_at DATETIME(6) NULL, INDEX(user_id, revoked_at), FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $db->execute('CREATE TABLE webauthn_challenges (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, session_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, challenge_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE, purpose VARCHAR(16) NOT NULL, expires_at DATETIME(6) NOT NULL, consumed_at DATETIME(6) NULL, INDEX(expires_at), FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    public function down(Connection $db): void
    {
        $db->execute('DROP TABLE IF EXISTS webauthn_challenges');
        $db->execute('DROP TABLE IF EXISTS webauthn_credentials');
    }
};
