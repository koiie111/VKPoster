<?php

declare(strict_types=1);

namespace App\Kernel\Console\Command;

use App\Kernel\Console\Command;
use App\Kernel\Console\Output;
use App\Kernel\Database\Migrator;

/**
 * `migrate`: apply all pending migrations.
 */
final class MigrateCommand implements Command
{
    public function __construct(private readonly Migrator $migrator)
    {
    }

    public function name(): string
    {
        return 'migrate';
    }

    public function description(): string
    {
        return 'Apply pending database migrations';
    }

    public function run(array $args, Output $out): int
    {
        $applied = $this->migrator->migrate();
        foreach ($applied as $name) {
            $out->line('migrated: ' . $name);
        }
        if ($applied === []) {
            $out->line('Nothing to migrate.');
        }

        return 0;
    }
}
