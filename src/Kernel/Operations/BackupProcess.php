<?php

declare(strict_types=1);

namespace App\Kernel\Operations;

use Closure;
use RuntimeException;

/** Bounded Oracle CLI execution with monotonic elapsed time and reaping; never exposes argv, stderr or credentials. */
final class BackupProcess
{
    /** @param (Closure(): float)|null $monotonicSeconds Test seam for elapsed time, never a wall clock. */
    public function __construct(private readonly ?Closure $monotonicSeconds = null)
    {
    }

    /** @param list<string> $command */
    public function run(array $command, ?string $input, string $output, int $timeout): void
    {
        $process = proc_open(
            $command,
            [0 => ['file', $input ?? '/dev/null', 'r'], 1 => ['file', $output, 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Backup database client unavailable.');
        }
        $deadline = $this->now() + $timeout;
        $timedOut = false;
        try {
            while (true) {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    break;
                }
                if ($this->now() >= $deadline) {
                    $timedOut = true;
                    // SIGKILL is deliberate: no descendants/shell, and restore must stop before returning failure.
                    proc_terminate($process, 9);
                    break;
                }
                usleep(100000);
            }
        } finally {
            // Reap even on timeout; a killed or partially applied restore must never be reported as success.
            $exit = proc_close($process);
        }
        if ($timedOut) {
            throw new RuntimeException('Backup database operation timed out. Restore may be partial: recreate the isolated restore target before retry; never drop the production database.');
        }
        if ($status['exitcode'] !== 0 && $exit !== 0) {
            throw new RuntimeException('Backup database operation failed. Restore may be partial: recreate the isolated restore target before retry.');
        }
    }

    private function now(): float
    {
        return $this->monotonicSeconds !== null ? ($this->monotonicSeconds)() : hrtime(true) / 1_000_000_000;
    }
}
