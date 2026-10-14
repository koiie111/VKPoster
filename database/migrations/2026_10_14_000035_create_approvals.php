<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Migration;

return new class () implements Migration {
    public function up(Connection $db): void
    {
        $suffix = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $db->execute('CREATE TABLE workspace_approval_settings (workspace_id BIGINT UNSIGNED PRIMARY KEY, required BOOLEAN NOT NULL DEFAULT FALSE, approvers_json JSON NOT NULL, brand_name VARCHAR(100) NULL, brand_color CHAR(7) NULL, brand_logo CHAR(26) NULL, updated_at DATETIME(6) NOT NULL, FOREIGN KEY(workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE)' . $suffix);
        $db->execute('CREATE TABLE post_versions (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, workspace_id BIGINT UNSIGNED NOT NULL, post_id BIGINT UNSIGNED NOT NULL, revision_hash CHAR(64) NOT NULL, snapshot_json JSON NOT NULL, actor_id BIGINT UNSIGNED NULL, created_at DATETIME(6) NOT NULL, INDEX post_history(workspace_id,post_id,id), FOREIGN KEY(workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE, FOREIGN KEY(post_id) REFERENCES posts(id) ON DELETE CASCADE, FOREIGN KEY(actor_id) REFERENCES users(id) ON DELETE SET NULL)' . $suffix);
        $db->execute('CREATE TABLE guest_links (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, public_id CHAR(26) NOT NULL UNIQUE, workspace_id BIGINT UNSIGNED NOT NULL, token_hash CHAR(64) NOT NULL UNIQUE, pin_hash VARCHAR(255) NULL, expires_at DATETIME(6) NOT NULL, revoked_at DATETIME(6) NULL, created_by BIGINT UNSIGNED NOT NULL, created_at DATETIME(6) NOT NULL, scope_json JSON NOT NULL, INDEX guest_workspace(workspace_id,id), FOREIGN KEY(workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE, FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE CASCADE)' . $suffix);
        $db->execute('CREATE TABLE guest_link_posts (workspace_id BIGINT UNSIGNED NOT NULL, link_id BIGINT UNSIGNED NOT NULL, post_id BIGINT UNSIGNED NOT NULL, PRIMARY KEY(link_id,post_id), FOREIGN KEY(workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE, FOREIGN KEY(link_id) REFERENCES guest_links(id) ON DELETE CASCADE, FOREIGN KEY(post_id) REFERENCES posts(id) ON DELETE CASCADE)' . $suffix);
        $db->execute('CREATE TABLE approvals (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, workspace_id BIGINT UNSIGNED NOT NULL, post_id BIGINT UNSIGNED NOT NULL, revision_hash CHAR(64) NOT NULL, decision VARCHAR(24) NOT NULL, actor_id BIGINT UNSIGNED NULL, guest_link_id BIGINT UNSIGNED NULL, created_at DATETIME(6) NOT NULL, INDEX approval_revision(workspace_id,post_id,revision_hash,id), FOREIGN KEY(workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE, FOREIGN KEY(post_id) REFERENCES posts(id) ON DELETE CASCADE, FOREIGN KEY(actor_id) REFERENCES users(id) ON DELETE SET NULL, FOREIGN KEY(guest_link_id) REFERENCES guest_links(id) ON DELETE SET NULL)' . $suffix);
        $db->execute('CREATE TABLE post_comments (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, public_id CHAR(26) NOT NULL UNIQUE, workspace_id BIGINT UNSIGNED NOT NULL, post_id BIGINT UNSIGNED NOT NULL, parent_id BIGINT UNSIGNED NULL, actor_id BIGINT UNSIGNED NULL, guest_link_id BIGINT UNSIGNED NULL, body TEXT NOT NULL, created_at DATETIME(6) NOT NULL, INDEX comment_thread(workspace_id,post_id,id), FOREIGN KEY(workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE, FOREIGN KEY(post_id) REFERENCES posts(id) ON DELETE CASCADE, FOREIGN KEY(parent_id) REFERENCES post_comments(id) ON DELETE CASCADE, FOREIGN KEY(actor_id) REFERENCES users(id) ON DELETE SET NULL, FOREIGN KEY(guest_link_id) REFERENCES guest_links(id) ON DELETE SET NULL)' . $suffix);
    }
    public function down(Connection $db): void
    {
        $db->execute("UPDATE posts SET status='draft' WHERE status IN ('pending_approval','approved','changes_requested')");
        foreach (['post_comments', 'approvals', 'guest_link_posts', 'guest_links', 'post_versions', 'workspace_approval_settings'] as $table) {
            $db->execute('DROP TABLE ' . $table);
        }
    }
};
