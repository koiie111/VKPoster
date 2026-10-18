<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Migration;

return new class () implements Migration {
    public function up(Connection $db): void
    {
        // A second reference a provider gives us after the order exists (Telegram Stars: `user:charge` of the successful payment, needed to refund).
        $db->execute('ALTER TABLE payments ADD COLUMN provider_ref VARCHAR(190) NULL AFTER provider_status');
    }

    public function down(Connection $db): void
    {
        $db->execute('ALTER TABLE payments DROP COLUMN provider_ref');
    }
};
