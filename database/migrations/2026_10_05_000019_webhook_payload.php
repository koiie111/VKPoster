<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Migration;

return new class () implements Migration {
    public function up(Connection $db): void
    {
        // The notification as the provider sent it, with personal and secret fields masked (see WebhookMask): what the admin area shows.
        $db->execute('ALTER TABLE webhook_events ADD COLUMN payload TEXT NULL AFTER detail');
    }

    public function down(Connection $db): void
    {
        $db->execute('ALTER TABLE webhook_events DROP COLUMN payload');
    }
};
