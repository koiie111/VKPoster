<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Migration;

return new class () implements Migration {
    public function up(Connection $db): void
    {
        // `public_id` (ULID) is what appears in URLs; the numeric id never leaves the server.
        // Deleting the owner's account removes the workspace with everything in it (a deliberate account-deletion rule).
        $db->execute('CREATE TABLE workspaces (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            public_id CHAR(26) NOT NULL,
            owner_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(100) NOT NULL,
            timezone VARCHAR(64) NOT NULL DEFAULT \'Europe/Moscow\',
            locale VARCHAR(10) NOT NULL DEFAULT \'ru\',
            is_personal TINYINT(1) NOT NULL DEFAULT 0,
            plan_id BIGINT UNSIGNED NULL,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            UNIQUE KEY uq_workspaces_public (public_id),
            KEY idx_workspaces_owner (owner_id),
            CONSTRAINT fk_workspaces_owner FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $db->execute('CREATE TABLE workspace_members (
            workspace_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            public_id CHAR(26) NOT NULL,
            role VARCHAR(16) NOT NULL,
            channels_restricted TINYINT(1) NOT NULL DEFAULT 0,
            invited_by BIGINT UNSIGNED NULL,
            joined_at DATETIME(6) NOT NULL,
            PRIMARY KEY (workspace_id, user_id),
            UNIQUE KEY uq_members_public (public_id),
            KEY idx_members_user (user_id),
            CONSTRAINT fk_members_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE,
            CONSTRAINT fk_members_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        // Only the SHA-256 of the emailed token is stored. An invitation is open while accepted_at and revoked_at are NULL and expires_at is in the future.
        $db->execute('CREATE TABLE invitations (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            public_id CHAR(26) NOT NULL,
            workspace_id BIGINT UNSIGNED NOT NULL,
            email VARCHAR(254) NOT NULL,
            role VARCHAR(16) NOT NULL,
            token_hash CHAR(64) NOT NULL,
            invited_by BIGINT UNSIGNED NULL,
            expires_at DATETIME(6) NOT NULL,
            accepted_at DATETIME(6) NULL,
            revoked_at DATETIME(6) NULL,
            created_at DATETIME(6) NOT NULL,
            UNIQUE KEY uq_invitations_public (public_id),
            UNIQUE KEY uq_invitations_token (token_hash),
            KEY idx_invitations_workspace (workspace_id, email),
            CONSTRAINT fk_invitations_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        // A member sees every channel unless channels_restricted is set (always the case for clients); then only the rows below count.
        // channel_id has no foreign key yet: the channels table arrives in stage 06, which adds the constraint.
        $db->execute('CREATE TABLE member_channel_access (
            workspace_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            channel_id BIGINT UNSIGNED NOT NULL,
            granted_at DATETIME(6) NOT NULL,
            PRIMARY KEY (workspace_id, user_id, channel_id),
            CONSTRAINT fk_channel_access_member FOREIGN KEY (workspace_id, user_id) REFERENCES workspace_members (workspace_id, user_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    public function down(Connection $db): void
    {
        $db->execute('DROP TABLE IF EXISTS member_channel_access');
        $db->execute('DROP TABLE IF EXISTS invitations');
        $db->execute('DROP TABLE IF EXISTS workspace_members');
        $db->execute('DROP TABLE IF EXISTS workspaces');
    }
};
