<?php

declare(strict_types=1);

namespace App\Kernel\Console\Command;

use App\Kernel\Console\Command;
use App\Kernel\Console\Output;
use App\Kernel\Queue\Worker;

/**
 * `queue:work [--queue=default] [--sleep=2] [--max-jobs=N]`: process jobs until SIGTERM.
 */
final class QueueWorkCommand implements Command
{
    public function __construct(private readonly Worker $worker, private readonly \App\Support\Heartbeat $heartbeat)
    {
    }

    public function name(): string
    {
        return 'queue:work';
    }

    public function description(): string
    {
        return 'Run the queue worker (stops gracefully on SIGTERM)';
    }

    public function run(array $args, Output $out): int
    {
        $queue = 'default';
        $sleep = 2;
        $max = null;
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--queue=')) {
                $queue = substr($arg, 8);
            } elseif (str_starts_with($arg, '--sleep=')) {
                $sleep = max(1, (int) substr($arg, 8));
            } elseif (str_starts_with($arg, '--max-jobs=')) {
                $max = max(1, (int) substr($arg, 11));
            }
        }
        $this->worker->onPulse(fn () => $this->heartbeat->beat('worker'));
        $out->line(sprintf('Worker started on queue "%s".', $queue));
        $this->worker->work($queue, $sleep, $max);
        $out->line('Worker stopped.');

        return 0;
    }
}
