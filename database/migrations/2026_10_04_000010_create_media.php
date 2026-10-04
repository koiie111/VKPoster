<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Migration;

return new class () implements Migration {
    public function up(Connection $db): void
    {
        // Folders are flat (one level): enough for "by client / by campaign" and easy to understand.
        // Deleting a folder keeps its files (they move to the library root).
        $db->execute('CREATE TABLE media_folders (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            public_id CHAR(26) NOT NULL,
            workspace_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(100) NOT NULL,
            created_at DATETIME(6) NOT NULL,
            UNIQUE KEY uq_media_folders_public (public_id),
            UNIQUE KEY uq_media_folders_name (workspace_id, name),
            CONSTRAINT fk_media_folders_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        // `sha256` is the hash of the file as uploaded (before re-encoding): the same file is stored once per workspace.
        // `size` is the stored (re-encoded) size and counts against the quota; cached variants do not.
        // `variants_json` maps a variant name to {key, size, width, height, mime}; `duration_ms` and `codec` are for videos only.
        $db->execute('CREATE TABLE media (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            public_id CHAR(26) NOT NULL,
            workspace_id BIGINT UNSIGNED NOT NULL,
            uploader_id BIGINT UNSIGNED NULL,
            folder_id BIGINT UNSIGNED NULL,
            kind VARCHAR(16) NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            storage_key VARCHAR(255) NOT NULL,
            thumb_key VARCHAR(255) NULL,
            mime VARCHAR(100) NOT NULL,
            size BIGINT UNSIGNED NOT NULL,
            width INT UNSIGNED NULL,
            height INT UNSIGNED NULL,
            duration_ms INT UNSIGNED NULL,
            codec VARCHAR(32) NULL,
            animated TINYINT(1) NOT NULL DEFAULT 0,
            sha256 CHAR(64) NOT NULL,
            variants_json TEXT NULL,
            created_at DATETIME(6) NOT NULL,
            UNIQUE KEY uq_media_public (public_id),
            UNIQUE KEY uq_media_sha (workspace_id, sha256),
            KEY idx_media_list (workspace_id, folder_id, id),
            KEY idx_media_kind (workspace_id, kind, id),
            CONSTRAINT fk_media_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE,
            CONSTRAINT fk_media_uploader FOREIGN KEY (uploader_id) REFERENCES users (id) ON DELETE SET NULL,
            CONSTRAINT fk_media_folder FOREIGN KEY (folder_id) REFERENCES media_folders (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        // `position` is one of tl tc tr ml mc mr bl bc br; opacity, scale (share of the picture width) and margin
        // (share of the shorter side) are percentages.
        $db->execute('CREATE TABLE watermarks (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            public_id CHAR(26) NOT NULL,
            workspace_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(100) NOT NULL,
            storage_key VARCHAR(255) NOT NULL,
            width INT UNSIGNED NOT NULL,
            height INT UNSIGNED NOT NULL,
            position CHAR(2) NOT NULL DEFAULT \'br\',
            opacity TINYINT UNSIGNED NOT NULL DEFAULT 70,
            scale TINYINT UNSIGNED NOT NULL DEFAULT 20,
            margin TINYINT UNSIGNED NOT NULL DEFAULT 3,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            UNIQUE KEY uq_watermarks_public (public_id),
            KEY idx_watermarks_workspace (workspace_id),
            CONSTRAINT fk_watermarks_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    public function down(Connection $db): void
    {
        $db->execute('DROP TABLE IF EXISTS watermarks');
        $db->execute('DROP TABLE IF EXISTS media');
        $db->execute('DROP TABLE IF EXISTS media_folders');
    }
};
