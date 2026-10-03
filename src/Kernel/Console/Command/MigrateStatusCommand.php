<?php

declare(strict_types=1);

namespace App\Kernel\Console\Command;

use App\Kernel\Console\Command;
use App\Kernel\Console\Output;
use App\Kernel\Database\Migrator;

/**
 * `migrate:status`: list migrations and whether they are applied.
 */
final class MigrateStatusCommand implements Command
{
    public function __construct(private readonly Migrator $migrator)
    {
    }

    public function name(): string
    {
        return 'migrate:status';
    }

    public function description(): string
    {
        return 'Show which migrations are applied';
    }

    public function run(array $args, Output $out): int
    {
        foreach ($this->migrator->status() as $row) {
            $out->line(sprintf('%-8s %s', $row['applied'] ? 'applied' : 'pending', $row['migration']));
        }

        return 0;
    }
}
