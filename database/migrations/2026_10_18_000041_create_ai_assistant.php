<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Migration;

return new class () implements Migration {
    public function up(Connection $db): void
    {
        $suffix = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        // Language-model providers the owner connected in the admin area. `config_enc` is the JSON of base_url, model, api_key
        // encrypted with `Crypto`. A preset code (`openai`, `yandex`, …) or `custom-<slug>` for any OpenAI-compatible endpoint.
        $db->execute('CREATE TABLE ai_providers (
            code VARCHAR(40) NOT NULL PRIMARY KEY,
            kind VARCHAR(24) NOT NULL,
            label VARCHAR(80) NOT NULL,
            enabled BOOLEAN NOT NULL DEFAULT FALSE,
            is_default BOOLEAN NOT NULL DEFAULT FALSE,
            config_enc MEDIUMTEXT NULL,
            updated_by BIGINT UNSIGNED NULL,
            updated_at DATETIME(6) NOT NULL
        )' . $suffix);
        // Per workspace: the brand voice that goes into every prompt, and an optional own endpoint (bring your own key: no credits).
        $db->execute('CREATE TABLE ai_workspace_settings (
            workspace_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            brand_json TEXT NULL,
            byok_enc MEDIUMTEXT NULL,
            byok_enabled BOOLEAN NOT NULL DEFAULT FALSE,
            updated_at DATETIME(6) NOT NULL,
            CONSTRAINT fk_ai_workspace_settings_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE
        )' . $suffix);
        // One row per request: metrics only, never the text of a post or of the answer.
        $db->execute('CREATE TABLE ai_usage (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            workspace_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NULL,
            action VARCHAR(24) NOT NULL,
            provider VARCHAR(40) NOT NULL,
            model VARCHAR(120) NOT NULL DEFAULT \'\',
            byok BOOLEAN NOT NULL DEFAULT FALSE,
            status VARCHAR(24) NOT NULL,
            credits INT UNSIGNED NOT NULL DEFAULT 0,
            tokens_in INT UNSIGNED NOT NULL DEFAULT 0,
            tokens_out INT UNSIGNED NOT NULL DEFAULT 0,
            duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME(6) NOT NULL,
            KEY idx_ai_usage_workspace (workspace_id, created_at),
            KEY idx_ai_usage_created (created_at),
            CONSTRAINT fk_ai_usage_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE
        )' . $suffix);
    }

    public function down(Connection $db): void
    {
        $db->execute('DROP TABLE ai_usage');
        $db->execute('DROP TABLE ai_workspace_settings');
        $db->execute('DROP TABLE ai_providers');
    }
};
