<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Migration;

return new class () implements Migration {
    public function up(Connection $db): void
    {
        $table = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        // A post of a workspace. `status`: draft | scheduled | publishing | published | partially_failed | failed | cancelled (derived from
        // the publications once they exist, see PostStatusAggregator). `base_text` is the common text; `media_ids_json` the library files
        // (public ids, in order) and `options_json` the shared options (buttons, silent, pin, delete_after_minutes, ...).
        $db->execute('CREATE TABLE posts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            public_id CHAR(26) NOT NULL,
            workspace_id BIGINT UNSIGNED NOT NULL,
            author_id BIGINT UNSIGNED NULL,
            status VARCHAR(20) NOT NULL DEFAULT \'draft\',
            base_text MEDIUMTEXT NOT NULL,
            media_ids_json TEXT NULL,
            options_json TEXT NULL,
            per_network TINYINT(1) NOT NULL DEFAULT 0,
            scheduled_at DATETIME(6) NULL,
            timezone VARCHAR(64) NOT NULL DEFAULT \'UTC\',
            published_at DATETIME(6) NULL,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            UNIQUE KEY uq_posts_public (public_id),
            KEY idx_posts_calendar (workspace_id, scheduled_at),
            KEY idx_posts_status (workspace_id, status),
            CONSTRAINT fk_posts_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE,
            CONSTRAINT fk_posts_author FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE SET NULL
        )' . $table);

        // One variant per channel. A NULL `text`, `media_ids_json` or `options_json` means "same as the post", so a variant follows
        // the common text until somebody changes it. `platform` and `channel_name` are a snapshot: the history stays readable after
        // the channel is disconnected (`channel_id` then becomes NULL).
        $db->execute('CREATE TABLE post_variants (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            post_id BIGINT UNSIGNED NOT NULL,
            workspace_id BIGINT UNSIGNED NOT NULL,
            channel_id BIGINT UNSIGNED NULL,
            platform VARCHAR(16) NOT NULL,
            channel_name VARCHAR(255) NOT NULL,
            text MEDIUMTEXT NULL,
            media_ids_json TEXT NULL,
            options_json TEXT NULL,
            UNIQUE KEY uq_variants_channel (post_id, channel_id),
            KEY idx_variants_channel (channel_id),
            CONSTRAINT fk_variants_post FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE,
            CONSTRAINT fk_variants_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE,
            CONSTRAINT fk_variants_channel FOREIGN KEY (channel_id) REFERENCES channels (id) ON DELETE SET NULL
        )' . $table);

        // One publication = one variant going out once. `status`: queued | sending | sent | failed | unknown | cancelled.
        // `due_at` is when it was meant to go out (the latency metric), `run_at` when the next attempt may start (a retry moves it).
        // `enqueued_at` marks that a job exists for the current `run_at`; the scheduler skips those. The unique idempotency key makes
        // a second publication for the same variant impossible.
        $db->execute('CREATE TABLE publications (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            public_id CHAR(26) NOT NULL,
            workspace_id BIGINT UNSIGNED NOT NULL,
            post_id BIGINT UNSIGNED NOT NULL,
            variant_id BIGINT UNSIGNED NOT NULL,
            channel_id BIGINT UNSIGNED NULL,
            status VARCHAR(16) NOT NULL DEFAULT \'queued\',
            attempt INT UNSIGNED NOT NULL DEFAULT 0,
            due_at DATETIME(6) NOT NULL,
            run_at DATETIME(6) NOT NULL,
            enqueued_at DATETIME(6) NULL,
            idempotency_key CHAR(64) NOT NULL,
            external_post_id VARCHAR(100) NULL,
            external_ids_json TEXT NULL,
            external_url VARCHAR(500) NULL,
            error_code VARCHAR(32) NULL,
            error_message VARCHAR(500) NULL,
            error_detail TEXT NULL,
            started_at DATETIME(6) NULL,
            sent_at DATETIME(6) NULL,
            delete_at DATETIME(6) NULL,
            delete_enqueued_at DATETIME(6) NULL,
            deleted_at DATETIME(6) NULL,
            delete_error VARCHAR(500) NULL,
            pinned TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            UNIQUE KEY uq_publications_public (public_id),
            UNIQUE KEY uq_publications_idempotency (idempotency_key),
            KEY idx_publications_due (status, enqueued_at, run_at),
            KEY idx_publications_post (post_id),
            KEY idx_publications_variant (variant_id),
            KEY idx_publications_channel (channel_id, status),
            KEY idx_publications_delete (delete_at, deleted_at),
            CONSTRAINT fk_publications_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE,
            CONSTRAINT fk_publications_post FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE,
            CONSTRAINT fk_publications_variant FOREIGN KEY (variant_id) REFERENCES post_variants (id) ON DELETE CASCADE,
            CONSTRAINT fk_publications_channel FOREIGN KEY (channel_id) REFERENCES channels (id) ON DELETE SET NULL
        )' . $table);

        // The journal: every attempt of every publication. `message` is for the owner, `detail` (technical) for administrators.
        $db->execute('CREATE TABLE publication_attempts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            publication_id BIGINT UNSIGNED NOT NULL,
            workspace_id BIGINT UNSIGNED NOT NULL,
            attempt INT UNSIGNED NOT NULL,
            outcome VARCHAR(20) NOT NULL,
            error_kind VARCHAR(20) NULL,
            message VARCHAR(500) NULL,
            detail TEXT NULL,
            started_at DATETIME(6) NOT NULL,
            finished_at DATETIME(6) NOT NULL,
            KEY idx_attempts_publication (publication_id),
            CONSTRAINT fk_attempts_publication FOREIGN KEY (publication_id) REFERENCES publications (id) ON DELETE CASCADE,
            CONSTRAINT fk_attempts_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE
        )' . $table);

        $db->execute('CREATE TABLE post_templates (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            public_id CHAR(26) NOT NULL,
            workspace_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(100) NOT NULL,
            payload_json MEDIUMTEXT NOT NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME(6) NOT NULL,
            UNIQUE KEY uq_templates_public (public_id),
            KEY idx_templates_workspace (workspace_id),
            CONSTRAINT fk_templates_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE,
            CONSTRAINT fk_templates_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
        )' . $table);

        // What was told to whom, and the person\'s choices. A row is both the in-app history and the delivery record.
        $db->execute('CREATE TABLE notifications (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            public_id CHAR(26) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            workspace_id BIGINT UNSIGNED NULL,
            type VARCHAR(32) NOT NULL,
            title VARCHAR(255) NOT NULL,
            body VARCHAR(1000) NOT NULL DEFAULT \'\',
            url VARCHAR(500) NULL,
            sent_email TINYINT(1) NOT NULL DEFAULT 0,
            sent_telegram TINYINT(1) NOT NULL DEFAULT 0,
            read_at DATETIME(6) NULL,
            created_at DATETIME(6) NOT NULL,
            UNIQUE KEY uq_notifications_public (public_id),
            KEY idx_notifications_user (user_id, created_at),
            CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
            CONSTRAINT fk_notifications_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE
        )' . $table);

        $db->execute('CREATE TABLE notification_settings (
            user_id BIGINT UNSIGNED NOT NULL,
            type VARCHAR(32) NOT NULL,
            email TINYINT(1) NOT NULL,
            telegram TINYINT(1) NOT NULL,
            PRIMARY KEY (user_id, type),
            CONSTRAINT fk_notification_settings_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        )' . $table);

        // The private chat of a user with the shared bot, linked through `/start TOKEN`. Tokens are stored as SHA-256 only.
        $db->execute('CREATE TABLE telegram_links (
            user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            chat_id BIGINT NOT NULL,
            username VARCHAR(64) NULL,
            linked_at DATETIME(6) NOT NULL,
            UNIQUE KEY uq_telegram_links_chat (chat_id),
            CONSTRAINT fk_telegram_links_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        )' . $table);
        $db->execute('CREATE TABLE telegram_link_tokens (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME(6) NOT NULL,
            used_at DATETIME(6) NULL,
            created_at DATETIME(6) NOT NULL,
            UNIQUE KEY uq_telegram_link_tokens_hash (token_hash),
            KEY idx_telegram_link_tokens_expires (expires_at),
            CONSTRAINT fk_telegram_link_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        )' . $table);
    }

    public function down(Connection $db): void
    {
        foreach (['telegram_link_tokens', 'telegram_links', 'notification_settings', 'notifications', 'post_templates', 'publication_attempts', 'publications', 'post_variants', 'posts'] as $name) {
            $db->execute('DROP TABLE IF EXISTS ' . $name);
        }
    }
};
