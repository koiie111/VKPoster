<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Migration;

return new class () implements Migration {
    public function up(Connection $db): void
    {
        $table = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        // Staff roles other than the superadmin (who is `users.is_superadmin`). The permission matrix of the roles is code
        // (`config/admin_permissions.php`), so a role cannot be edited into something unreviewed.
        $db->execute('CREATE TABLE staff_members (
            user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            role VARCHAR(16) NOT NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME(6) NOT NULL,
            CONSTRAINT fk_staff_members_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        )' . $table);

        // Private notes of staff about a person (never shown to the person).
        $db->execute('CREATE TABLE admin_notes (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            author_id BIGINT UNSIGNED NULL,
            body TEXT NOT NULL,
            created_at DATETIME(6) NOT NULL,
            KEY idx_admin_notes_user (user_id, created_at),
            CONSTRAINT fk_admin_notes_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
            CONSTRAINT fk_admin_notes_author FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE SET NULL
        )' . $table);

        // The reason a staff member gave when blocking; the person sees it when they try to sign in.
        $db->execute('ALTER TABLE users ADD COLUMN block_reason VARCHAR(500) NULL AFTER status');
    }

    public function down(Connection $db): void
    {
        $db->execute('ALTER TABLE users DROP COLUMN block_reason');
        $db->execute('DROP TABLE IF EXISTS admin_notes');
        $db->execute('DROP TABLE IF EXISTS staff_members');
    }
};
