<?php

declare(strict_types=1);

namespace App\Kernel\Console\Command;

use App\Kernel\Console\Command;
use App\Kernel\Console\Output;
use App\Kernel\Queue\Schedule;
use App\Support\Clock;

/**
 * `schedule:run [--once]`: run due periodic tasks at the start of every minute (or once and exit).
 */
final class ScheduleRunCommand implements Command
{
    private bool $stop = false;

    public function __construct(private readonly Schedule $schedule, private readonly Clock $clock, private readonly \App\Support\Heartbeat $heartbeat)
    {
    }

    public function name(): string
    {
        return 'schedule:run';
    }

    public function description(): string
    {
        return 'Run scheduled tasks every minute (--once: single pass)';
    }

    public function run(array $args, Output $out): int
    {
        if (in_array('--once', $args, true)) {
            $this->schedule->runDue($this->clock->now());

            return 0;
        }
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, function (): void {
                $this->stop = true;
            });
            pcntl_signal(SIGINT, function (): void {
                $this->stop = true;
            });
        }
        $out->line(sprintf('Scheduler started with %d task(s).', count($this->schedule->tasks())));
        while (!$this->shouldStop()) {
            $wait = 60 - ((int) $this->clock->now()->format('s'));
            for ($i = 0; $i < $wait * 10 && !$this->shouldStop(); ++$i) {
                $this->heartbeat->beat('scheduler');
                usleep(100_000);
            }
            if (!$this->shouldStop()) {
                $this->schedule->runDue($this->clock->now());
            }
        }
        $out->line('Scheduler stopped.');

        return 0;
    }

    /**
     * @phpstan-impure
     */
    private function shouldStop(): bool
    {
        pcntl_signal_dispatch();

        return $this->stop;
    }
}
