<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Migration;

return new class () implements Migration {
    public function up(Connection $db): void
    {
        $table = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        // Revisions of site documents edited in the admin area. `kind`: legal | help. The live text of a document is its newest published
        // revision (for legal ones: the one with the newest `version` date); the files in resources/ are the fallback. A draft is not live.
        $db->execute('CREATE TABLE cms_pages (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            kind VARCHAR(8) NOT NULL,
            slug VARCHAR(60) NOT NULL,
            title VARCHAR(200) NOT NULL,
            version VARCHAR(10) NULL,
            required TINYINT(1) NOT NULL DEFAULT 0,
            body_md MEDIUMTEXT NOT NULL,
            status VARCHAR(10) NOT NULL DEFAULT \'draft\',
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME(6) NOT NULL,
            published_at DATETIME(6) NULL,
            KEY idx_cms_pages_live (kind, slug, status, id)
        )' . $table);

        // Named texts of the landing page (and the FAQ as JSON under `faq`); a missing row means "the text written in the template".
        $db->execute('CREATE TABLE cms_blocks (
            name VARCHAR(60) NOT NULL PRIMARY KEY,
            value MEDIUMTEXT NOT NULL,
            updated_by BIGINT UNSIGNED NULL,
            updated_at DATETIME(6) NOT NULL
        )' . $table);

        // Notices shown inside the app. `plans_json` / `platforms_json` narrow the audience (NULL = everybody).
        $db->execute('CREATE TABLE announcements (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(150) NOT NULL,
            body VARCHAR(1000) NOT NULL,
            level VARCHAR(10) NOT NULL DEFAULT \'info\',
            plans_json TEXT NULL,
            platforms_json TEXT NULL,
            starts_at DATETIME(6) NOT NULL,
            ends_at DATETIME(6) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME(6) NOT NULL,
            KEY idx_announcements_active (is_active, starts_at, ends_at)
        )' . $table);
        $db->execute('CREATE TABLE announcement_dismissals (
            announcement_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (announcement_id, user_id),
            CONSTRAINT fk_dismissals_announcement FOREIGN KEY (announcement_id) REFERENCES announcements (id) ON DELETE CASCADE,
            CONSTRAINT fk_dismissals_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        )' . $table);

        // Customer support. A ticket comes from the "report a problem" form or from the shared Telegram bot. `context_json` is what the
        // service knew when it was opened (plan, latest publication errors). A message `kind` is customer | staff | note (note: internal).
        $db->execute('CREATE TABLE support_tickets (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            public_id CHAR(26) NOT NULL,
            user_id BIGINT UNSIGNED NULL,
            email VARCHAR(254) NULL,
            subject VARCHAR(200) NOT NULL,
            status VARCHAR(10) NOT NULL DEFAULT \'open\',
            source VARCHAR(10) NOT NULL,
            assignee_id BIGINT UNSIGNED NULL,
            context_json TEXT NULL,
            telegram_chat_id BIGINT NULL,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            UNIQUE KEY uq_tickets_public (public_id),
            KEY idx_tickets_status (status, updated_at),
            KEY idx_tickets_user (user_id),
            CONSTRAINT fk_tickets_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
            CONSTRAINT fk_tickets_assignee FOREIGN KEY (assignee_id) REFERENCES users (id) ON DELETE SET NULL
        )' . $table);
        $db->execute('CREATE TABLE support_messages (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ticket_id BIGINT UNSIGNED NOT NULL,
            kind VARCHAR(10) NOT NULL,
            author_id BIGINT UNSIGNED NULL,
            body TEXT NOT NULL,
            delivered_via VARCHAR(10) NULL,
            created_at DATETIME(6) NOT NULL,
            KEY idx_messages_ticket (ticket_id, id),
            CONSTRAINT fk_messages_ticket FOREIGN KEY (ticket_id) REFERENCES support_tickets (id) ON DELETE CASCADE
        )' . $table);

        // Marketing mail: only to people who agreed (`users.marketing_opt_in_at`) and have not left (`marketing_unsubscribed_at`).
        $db->execute('ALTER TABLE users ADD COLUMN marketing_opt_in_at DATETIME(6) NULL AFTER consent_at, ADD COLUMN marketing_unsubscribed_at DATETIME(6) NULL AFTER marketing_opt_in_at');
        $db->execute('CREATE TABLE email_campaigns (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            public_id CHAR(26) NOT NULL,
            name VARCHAR(150) NOT NULL,
            subject VARCHAR(200) NOT NULL,
            body_md TEXT NOT NULL,
            segment_json TEXT NOT NULL,
            status VARCHAR(10) NOT NULL DEFAULT \'draft\',
            total INT UNSIGNED NOT NULL DEFAULT 0,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME(6) NOT NULL,
            started_at DATETIME(6) NULL,
            finished_at DATETIME(6) NULL,
            UNIQUE KEY uq_campaigns_public (public_id)
        )' . $table);
        $db->execute('CREATE TABLE email_campaign_recipients (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            campaign_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NULL,
            email VARCHAR(254) NOT NULL,
            status VARCHAR(10) NOT NULL DEFAULT \'queued\',
            error VARCHAR(255) NULL,
            sent_at DATETIME(6) NULL,
            unsubscribed_at DATETIME(6) NULL,
            UNIQUE KEY uq_recipients (campaign_id, email),
            KEY idx_recipients_status (campaign_id, status),
            CONSTRAINT fk_recipients_campaign FOREIGN KEY (campaign_id) REFERENCES email_campaigns (id) ON DELETE CASCADE
        )' . $table);

        // Edited texts of system emails (see MailTemplates): a NULL column keeps the text written in the template.
        $db->execute('CREATE TABLE mail_templates (
            template VARCHAR(40) NOT NULL PRIMARY KEY,
            subject VARCHAR(200) NULL,
            body_md TEXT NULL,
            updated_by BIGINT UNSIGNED NULL,
            updated_at DATETIME(6) NOT NULL
        )' . $table);

        // Invitation codes for the invite-only registration mode. Only the SHA-256 of a code is stored.
        $db->execute('CREATE TABLE invite_codes (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code_hash CHAR(64) NOT NULL,
            hint VARCHAR(8) NOT NULL,
            note VARCHAR(150) NULL,
            max_uses INT UNSIGNED NOT NULL DEFAULT 1,
            uses INT UNSIGNED NOT NULL DEFAULT 0,
            expires_at DATETIME(6) NULL,
            revoked_at DATETIME(6) NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME(6) NOT NULL,
            UNIQUE KEY uq_invite_codes_hash (code_hash)
        )' . $table);
    }

    public function down(Connection $db): void
    {
        foreach (['invite_codes', 'mail_templates', 'email_campaign_recipients', 'email_campaigns'] as $name) {
            $db->execute('DROP TABLE IF EXISTS ' . $name);
        }
        $db->execute('ALTER TABLE users DROP COLUMN marketing_unsubscribed_at, DROP COLUMN marketing_opt_in_at');
        foreach (['support_messages', 'support_tickets', 'announcement_dismissals', 'announcements', 'cms_blocks', 'cms_pages'] as $name) {
            $db->execute('DROP TABLE IF EXISTS ' . $name);
        }
    }
};
