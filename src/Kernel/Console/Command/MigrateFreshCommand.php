<?php

declare(strict_types=1);

namespace App\Kernel\Console\Command;

use App\Kernel\Config;
use App\Kernel\Console\Command;
use App\Kernel\Console\Output;
use App\Kernel\Database\Migrator;

/**
 * `migrate:fresh`: drop every table and migrate from scratch. Refused in production.
 */
final class MigrateFreshCommand implements Command
{
    public function __construct(private readonly Migrator $migrator, private readonly Config $config)
    {
    }

    public function name(): string
    {
        return 'migrate:fresh';
    }

    public function description(): string
    {
        return 'Drop all tables and migrate again (not in production)';
    }

    public function run(array $args, Output $out): int
    {
        if ($this->config->isProduction()) {
            $out->error('migrate:fresh is disabled when APP_ENV=production.');

            return 1;
        }
        foreach ($this->migrator->fresh() as $name) {
            $out->line('migrated: ' . $name);
        }

        return 0;
    }
}
