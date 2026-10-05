<?php

declare(strict_types=1);

namespace App\Kernel\Console\Command;

use App\Domain\Admin\DemoData;
use App\Kernel\Config;
use App\Kernel\Console\Command;
use App\Kernel\Console\Output;

/**
 * `seed:demo [--users=500] [--days=180] [--keep]`: fill the database with demo people, channels, publications and payments for the last months
 * (to look at the admin dashboard and to load-test the aggregation). Removes the previous demo data first unless `--keep`. Local only.
 */
final class SeedDemoCommand implements Command
{
    public function __construct(private readonly Config $config, private readonly DemoData $demo)
    {
    }

    public function name(): string
    {
        return 'seed:demo';
    }

    public function description(): string
    {
        return 'Demo data for the admin dashboard: seed:demo [--users=500] [--days=180] [--keep] (APP_ENV=local only)';
    }

    public function run(array $args, Output $out): int
    {
        if ($this->config->string('app.env') !== 'local') {
            $out->error('seed:demo works only with APP_ENV=local.');

            return 1;
        }
        $users = 500;
        $days = 180;
        $keep = false;
        foreach ($args as $arg) {
            if (preg_match('/^--users=(\d{1,5})$/', $arg, $m) === 1) {
                $users = max(1, (int) $m[1]);
            } elseif (preg_match('/^--days=(\d{1,4})$/', $arg, $m) === 1) {
                $days = max(7, (int) $m[1]);
            } elseif ($arg === '--keep') {
                $keep = true;
            }
        }
        if (!$keep) {
            $out->line('Removed demo people: ' . $this->demo->purge());
        }
        $started = microtime(true);
        $summary = $this->demo->generate($users, $days);
        foreach ($summary as $name => $count) {
            $out->line(sprintf('%-14s %d', $name, $count));
        }
        $out->line(sprintf('Done in %.1f s. Open /admin (local: /dev/login-as/staff@ezposter.local).', microtime(true) - $started));

        return 0;
    }
}
