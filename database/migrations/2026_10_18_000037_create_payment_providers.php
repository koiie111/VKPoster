<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Migration;

return new class () implements Migration {
    public function up(Connection $db): void
    {
        $suffix = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        // One row per payment provider that the owner switched on or off or gave keys to in the admin area. `config_enc` is the JSON of the
        // provider's fields encrypted with `Crypto` (secrets never sit in plain text). No row = the environment decides.
        $db->execute('CREATE TABLE payment_providers (
            code VARCHAR(20) NOT NULL PRIMARY KEY,
            enabled BOOLEAN NOT NULL DEFAULT FALSE,
            config_enc MEDIUMTEXT NULL,
            updated_by BIGINT UNSIGNED NULL,
            updated_at DATETIME(6) NOT NULL
        )' . $suffix);
    }

    public function down(Connection $db): void
    {
        $db->execute('DROP TABLE payment_providers');
    }
};
