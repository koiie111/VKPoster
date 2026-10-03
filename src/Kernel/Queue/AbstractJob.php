<?php

declare(strict_types=1);

namespace App\Kernel\Queue;

/**
 * Base class with the default retry policy: 5 attempts, backoff 1 / 5 / 15 / 60 minutes.
 */
abstract class AbstractJob implements Job
{
    public function maxAttempts(): int
    {
        return 5;
    }

    public function backoff(int $attempt): int
    {
        $steps = [60, 300, 900, 3600];

        return $steps[min($attempt, count($steps)) - 1];
    }
}
