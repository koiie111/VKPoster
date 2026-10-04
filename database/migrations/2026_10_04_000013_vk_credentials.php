<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Migration;

return new class () implements Migration {
    public function up(Connection $db): void
    {
        // OAuth accounts (VK): one credential row is shared by every community connected through the same sign-in.
        // `device_id` is issued by VK ID together with the tokens and is required to refresh them; `account_id` is the VK user.
        $db->execute('ALTER TABLE platform_credentials ADD COLUMN device_id VARCHAR(100) NULL AFTER scopes, ADD COLUMN account_id VARCHAR(32) NULL AFTER device_id');
    }

    public function down(Connection $db): void
    {
        $db->execute('ALTER TABLE platform_credentials DROP COLUMN account_id, DROP COLUMN device_id');
    }
};
