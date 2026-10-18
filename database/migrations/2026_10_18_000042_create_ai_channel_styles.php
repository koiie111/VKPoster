<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Migration;

return new class () implements Migration {
    public function up(Connection $db): void
    {
        // The AI writing style of one channel (tone, format rules, forbidden words, example posts). It is added on top of the workspace's
        // brand voice whenever the assistant works on a post for that channel.
        $db->execute('CREATE TABLE ai_channel_styles (
            channel_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            workspace_id BIGINT UNSIGNED NOT NULL,
            style_json TEXT NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            KEY idx_ai_channel_styles_workspace (workspace_id),
            CONSTRAINT fk_ai_channel_styles_channel FOREIGN KEY (channel_id) REFERENCES channels (id) ON DELETE CASCADE,
            CONSTRAINT fk_ai_channel_styles_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    public function down(Connection $db): void
    {
        $db->execute('DROP TABLE ai_channel_styles');
    }
};
