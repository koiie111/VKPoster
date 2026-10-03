<?php

declare(strict_types=1);

namespace App\Kernel\Console\Command;

use App\Kernel\Console\Command;
use App\Kernel\Console\Output;
use App\Kernel\Database\Migrator;

/**
 * `migrate:rollback`: revert the last batch of migrations.
 */
final class MigrateRollbackCommand implements Command
{
    public function __construct(private readonly Migrator $migrator)
    {
    }

    public function name(): string
    {
        return 'migrate:rollback';
    }

    public function description(): string
    {
        return 'Revert the last batch of migrations';
    }

    public function run(array $args, Output $out): int
    {
        $reverted = $this->migrator->rollback();
        foreach ($reverted as $name) {
            $out->line('rolled back: ' . $name);
        }
        if ($reverted === []) {
            $out->line('Nothing to roll back.');
        }

        return 0;
    }
}
