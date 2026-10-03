<?php

declare(strict_types=1);

namespace App\Kernel\Console\Command;

use App\Kernel\Config;
use App\Kernel\Console\Command;
use App\Kernel\Console\Output;
use App\Kernel\Database\Connection;
use App\Kernel\Database\Seeder;
use App\Support\Fs;

/**
 * `seed`: run every seeder in `database/seeds/` (dev data). Refused in production.
 */
final class SeedCommand implements Command
{
    public function __construct(
        private readonly Connection $db,
        private readonly Config $config,
        private readonly string $seedDir,
    ) {
    }

    public function name(): string
    {
        return 'seed';
    }

    public function description(): string
    {
        return 'Load development seed data (not in production)';
    }

    public function run(array $args, Output $out): int
    {
        if ($this->config->isProduction()) {
            $out->error('seed is disabled when APP_ENV=production.');

            return 1;
        }
        $files = Fs::glob(rtrim($this->seedDir, '/') . '/*.php');
        foreach ($files as $file) {
            $seeder = require $file;
            if (!$seeder instanceof Seeder) {
                $out->error(basename($file) . ' must return a Seeder.');

                return 1;
            }
            $seeder->run($this->db);
            $out->line('seeded: ' . basename($file, '.php'));
        }
        if ($files === []) {
            $out->line('No seeders found.');
        }

        return 0;
    }
}
