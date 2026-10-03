<?php

declare(strict_types=1);

namespace App\Kernel\Database;

/**
 * Development seed data. Files in `database/seeds/` return an anonymous class implementing this.
 * Seeds must be idempotent and are refused in production.
 */
interface Seeder
{
    public function run(Connection $db): void;
}
