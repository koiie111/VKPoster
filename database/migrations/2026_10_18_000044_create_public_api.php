<?php

declare(strict_types=1);

use App\Kernel\Database\{Connection, Migration};

return new class () implements Migration {
    public function up(Connection $db): void
    {
        $db->execute("CREATE TABLE api_tokens (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, public_id CHAR(26) NOT NULL UNIQUE,
            workspace_id BIGINT UNSIGNED NOT NULL, created_by BIGINT UNSIGNED NOT NULL,
            name VARCHAR(100) NOT NULL, token_hash CHAR(64) NOT NULL UNIQUE, scopes_json JSON NOT NULL,
            expires_at DATETIME(6) NULL, revoked_at DATETIME(6) NULL, last_used_at DATETIME(6) NULL, created_at DATETIME(6) NOT NULL,
            INDEX(workspace_id, id), FOREIGN KEY(workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
            FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
        $db->execute("CREATE TABLE api_idempotency (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, workspace_id BIGINT UNSIGNED NOT NULL, token_id BIGINT UNSIGNED NOT NULL,
            key_hash CHAR(64) NOT NULL, request_hash CHAR(64) NOT NULL, status SMALLINT NULL, response_json MEDIUMTEXT NULL,
            created_at DATETIME(6) NOT NULL, expires_at DATETIME(6) NOT NULL,
            UNIQUE KEY api_once(token_id,key_hash), INDEX(expires_at),
            FOREIGN KEY(workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
            FOREIGN KEY(token_id) REFERENCES api_tokens(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
        $db->execute("CREATE TABLE webhook_subscriptions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, public_id CHAR(26) NOT NULL UNIQUE,
            workspace_id BIGINT UNSIGNED NOT NULL, created_by BIGINT UNSIGNED NOT NULL,
            url VARCHAR(2000) NOT NULL, secret_enc TEXT NOT NULL, events_json JSON NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1, consecutive_failures INT NOT NULL DEFAULT 0, created_at DATETIME(6) NOT NULL,
            FOREIGN KEY(workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
            FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE CASCADE, INDEX(workspace_id,id)
        ) ENGINE=InnoDB");
        $db->execute("CREATE TABLE outgoing_webhook_events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, public_id CHAR(26) NOT NULL UNIQUE,
            workspace_id BIGINT UNSIGNED NOT NULL, event_key VARCHAR(191) NOT NULL UNIQUE, type VARCHAR(64) NOT NULL,
            payload_json JSON NOT NULL, created_at DATETIME(6) NOT NULL, dispatched_at DATETIME(6) NULL,
            INDEX(dispatched_at,id), FOREIGN KEY(workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
        $db->execute("CREATE TABLE webhook_deliveries (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, public_id CHAR(26) NOT NULL UNIQUE,
            workspace_id BIGINT UNSIGNED NOT NULL, subscription_id BIGINT UNSIGNED NOT NULL, event_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending', attempts INT NOT NULL DEFAULT 0,
            next_attempt_at DATETIME(6) NOT NULL, completed_at DATETIME(6) NULL, enqueued_at DATETIME(6) NULL,
            lease_token CHAR(32) NULL, lease_until DATETIME(6) NULL, response_status SMALLINT NULL, error_code VARCHAR(40) NULL,
            UNIQUE KEY webhook_once(subscription_id,event_id), INDEX(status,next_attempt_at),
            FOREIGN KEY(workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
            FOREIGN KEY(subscription_id) REFERENCES webhook_subscriptions(id) ON DELETE CASCADE,
            FOREIGN KEY(event_id) REFERENCES outgoing_webhook_events(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
        $db->execute("CREATE TABLE webhook_delivery_attempts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, delivery_id BIGINT UNSIGNED NOT NULL,
            attempted_at DATETIME(6) NOT NULL, response_status SMALLINT NULL, error_code VARCHAR(40) NULL,
            FOREIGN KEY(delivery_id) REFERENCES webhook_deliveries(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
    }

    public function down(Connection $db): void
    {
        foreach (['webhook_delivery_attempts', 'webhook_deliveries', 'outgoing_webhook_events', 'webhook_subscriptions', 'api_idempotency', 'api_tokens'] as $table) {
            $db->execute('DROP TABLE ' . $table);
        }
    }
};
