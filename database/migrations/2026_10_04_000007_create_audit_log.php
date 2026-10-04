<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Migration;

return new class () implements Migration {
    public function up(Connection $db): void
    {
        // Append-only. No foreign keys on purpose: the trail must outlive deleted users and workspaces.
        $db->execute('CREATE TABLE audit_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            workspace_id BIGINT UNSIGNED NULL,
            actor_id BIGINT UNSIGNED NULL,
            action VARCHAR(64) NOT NULL,
            subject_type VARCHAR(32) NULL,
            subject_id VARCHAR(64) NULL,
            ip VARCHAR(45) NULL,
            meta_json JSON NULL,
            created_at DATETIME(6) NOT NULL,
            KEY idx_audit_actor (actor_id, created_at),
            KEY idx_audit_workspace (workspace_id, created_at),
            KEY idx_audit_action (action, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    public function down(Connection $db): void
    {
        $db->execute('DROP TABLE IF EXISTS audit_log');
    }
};
