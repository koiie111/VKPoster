<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Migration;

/** Reader identities are separate from publishing channels; only ciphertext is persisted. */
return new class () implements Migration {
    public function up(Connection $db): void
    {
        $db->execute('CREATE TABLE source_reader_accounts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            public_id CHAR(26) NOT NULL,
            workspace_id BIGINT UNSIGNED NULL,
            label VARCHAR(80) NOT NULL,
            telegram_user_id BIGINT UNSIGNED NULL,
            session_enc MEDIUMTEXT NULL,
            status VARCHAR(24) NOT NULL DEFAULT \'pending\',
            last_seen_at DATETIME(6) NULL,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            UNIQUE KEY uq_reader_account_public (public_id),
            UNIQUE KEY uq_reader_telegram_user (telegram_user_id),
            KEY idx_reader_account_scope (workspace_id, status),
            FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $db->execute('ALTER TABLE sources ADD COLUMN reader_account_id BIGINT UNSIGNED NULL, ADD KEY idx_source_reader_account (reader_account_id, enabled), ADD CONSTRAINT fk_source_reader_account FOREIGN KEY (reader_account_id) REFERENCES source_reader_accounts(id) ON DELETE SET NULL');
    }

    public function down(Connection $db): void
    {
        $db->execute('ALTER TABLE sources DROP FOREIGN KEY fk_source_reader_account, DROP KEY idx_source_reader_account, DROP COLUMN reader_account_id');
        $db->execute('DROP TABLE source_reader_accounts');
    }
};
