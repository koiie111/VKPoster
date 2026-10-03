<?php

declare(strict_types=1);

namespace App\Kernel\Database;

/**
 * A reversible schema change. Files in `database/migrations/` return an anonymous class implementing this.
 */
interface Migration
{
    public function up(Connection $db): void;

    public function down(Connection $db): void;
}
