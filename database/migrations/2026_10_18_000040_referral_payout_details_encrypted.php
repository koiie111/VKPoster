<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Migration;

return new class () implements Migration {
    public function up(Connection $db): void
    {
        // Where to send the money is a financial identifier: it is stored encrypted (`Crypto`) and wiped once the request is handled.
        $db->execute('ALTER TABLE referral_payouts MODIFY details TEXT NOT NULL');
    }

    public function down(Connection $db): void
    {
        $db->execute('ALTER TABLE referral_payouts MODIFY details VARCHAR(500) NOT NULL');
    }
};
