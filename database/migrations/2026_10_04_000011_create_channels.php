<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Migration;

return new class () implements Migration {
    public function up(Connection $db): void
    {
        // Secrets of a connected account (a customer's own bot token, later OAuth tokens). `secret_enc` and `refresh_enc`
        // hold `Crypto` ciphertext only (the key id is inside the payload). Channels of the shared bot have no credential row.
        $db->execute('CREATE TABLE platform_credentials (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            public_id CHAR(26) NOT NULL,
            workspace_id BIGINT UNSIGNED NOT NULL,
            platform VARCHAR(16) NOT NULL,
            kind VARCHAR(16) NOT NULL,
            secret_enc TEXT NOT NULL,
            refresh_enc TEXT NULL,
            expires_at DATETIME(6) NULL,
            scopes VARCHAR(500) NULL,
            hint VARCHAR(40) NOT NULL DEFAULT \'\',
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            UNIQUE KEY uq_credentials_public (public_id),
            KEY idx_credentials_workspace (workspace_id),
            CONSTRAINT fk_credentials_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        // `external_id` is the id on the platform (Telegram chat id). `mode` says whose bot publishes: the shared one or the customer\'s own.
        // `status`: active | paused | error | revoked. `settings_json` holds what the bot may do (post, edit, delete, pin) and platform extras.
        $db->execute('CREATE TABLE channels (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            public_id CHAR(26) NOT NULL,
            workspace_id BIGINT UNSIGNED NOT NULL,
            platform VARCHAR(16) NOT NULL,
            external_id VARCHAR(64) NOT NULL,
            mode VARCHAR(16) NOT NULL DEFAULT \'shared_bot\',
            title VARCHAR(255) NOT NULL,
            alias VARCHAR(100) NULL,
            username VARCHAR(64) NULL,
            kind VARCHAR(16) NOT NULL DEFAULT \'channel\',
            avatar_key VARCHAR(255) NULL,
            status VARCHAR(16) NOT NULL DEFAULT \'active\',
            credential_id BIGINT UNSIGNED NULL,
            settings_json TEXT NULL,
            last_health_at DATETIME(6) NULL,
            last_error VARCHAR(500) NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            UNIQUE KEY uq_channels_public (public_id),
            UNIQUE KEY uq_channels_external (workspace_id, platform, external_id),
            KEY idx_channels_health (status, last_health_at),
            KEY idx_channels_external (platform, external_id),
            CONSTRAINT fk_channels_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE,
            CONSTRAINT fk_channels_credential FOREIGN KEY (credential_id) REFERENCES platform_credentials (id) ON DELETE SET NULL,
            CONSTRAINT fk_channels_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        // One-time codes for "write /connect CODE in the channel". Only the SHA-256 of the code is stored.
        // `failure` tells the person waiting on the page why the last attempt did not connect (e.g. the bot cannot post).
        $db->execute('CREATE TABLE channel_connect_codes (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            public_id CHAR(26) NOT NULL,
            workspace_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            platform VARCHAR(16) NOT NULL,
            code_hash CHAR(64) NOT NULL,
            channel_id BIGINT UNSIGNED NULL,
            failure VARCHAR(255) NULL,
            expires_at DATETIME(6) NOT NULL,
            used_at DATETIME(6) NULL,
            created_at DATETIME(6) NOT NULL,
            UNIQUE KEY uq_connect_codes_public (public_id),
            UNIQUE KEY uq_connect_codes_hash (code_hash),
            KEY idx_connect_codes_expires (expires_at),
            CONSTRAINT fk_connect_codes_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE,
            CONSTRAINT fk_connect_codes_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
            CONSTRAINT fk_connect_codes_channel FOREIGN KEY (channel_id) REFERENCES channels (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        // Stage 04 left channel_id of the access list without a constraint until the channels table existed.
        $db->execute('DELETE FROM member_channel_access');
        $db->execute('ALTER TABLE member_channel_access ADD CONSTRAINT fk_channel_access_channel FOREIGN KEY (channel_id) REFERENCES channels (id) ON DELETE CASCADE');
    }

    public function down(Connection $db): void
    {
        $db->execute('ALTER TABLE member_channel_access DROP FOREIGN KEY fk_channel_access_channel');
        $db->execute('DROP TABLE IF EXISTS channel_connect_codes');
        $db->execute('DROP TABLE IF EXISTS channels');
        $db->execute('DROP TABLE IF EXISTS platform_credentials');
    }
};
