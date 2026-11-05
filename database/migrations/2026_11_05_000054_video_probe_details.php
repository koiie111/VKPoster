<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Migration;

return new class () implements Migration {
    public function up(Connection $db): void
    {
        $db->execute('ALTER TABLE media ADD audio_codec VARCHAR(40) NULL, ADD frame_rate DECIMAL(8,3) NULL');
    }

    public function down(Connection $db): void
    {
        $db->execute('ALTER TABLE media DROP COLUMN frame_rate, DROP COLUMN audio_codec');
    }
};
