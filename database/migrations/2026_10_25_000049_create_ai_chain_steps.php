<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Migration;

return new class () implements Migration {
    public function up(Connection $db): void
    {
        // The order in which the assistant tries models: a step is a provider (its key lives in `ai_providers`), an optional model
        // that overrides the provider's own, and a cap of requests per day (0 = no cap) whose day starts at a UTC offset, a pause after a failure
        // (0 = by reason), whether the user pays credits for its answers, and the actions it serves (empty = all). Empty chain = the old single default.
        $db->execute('CREATE TABLE ai_chain_steps (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            position SMALLINT UNSIGNED NOT NULL,
            provider_code VARCHAR(40) NOT NULL,
            model VARCHAR(120) NOT NULL DEFAULT \'\',
            label VARCHAR(80) NOT NULL DEFAULT \'\',
            daily_requests INT UNSIGNED NOT NULL DEFAULT 0,
            reset_offset_minutes SMALLINT NOT NULL DEFAULT 0,
            cooldown_seconds INT UNSIGNED NOT NULL DEFAULT 0,
            charge_credits BOOLEAN NOT NULL DEFAULT TRUE,
            actions VARCHAR(120) NOT NULL DEFAULT \'\',
            enabled BOOLEAN NOT NULL DEFAULT TRUE,
            updated_by BIGINT UNSIGNED NULL,
            updated_at DATETIME(6) NOT NULL,
            KEY ix_ai_chain_steps_position (position, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    public function down(Connection $db): void
    {
        $db->execute('DROP TABLE ai_chain_steps');
    }
};
