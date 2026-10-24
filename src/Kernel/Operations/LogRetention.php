<?php

declare(strict_types=1);

namespace App\Kernel\Operations;

use App\Support\Clock;
use App\Support\Fs;

/** Removes only dated application logs older than 30 days; symlinks and other storage are never followed. */
final class LogRetention
{
    public function __construct(private readonly Clock $clock)
    {
    }

    public function prune(string $directory): int
    {
        $removed = 0;
        $cutoff = $this->clock->now()->modify('-30 days')->format('Y-m-d');
        foreach (Fs::glob($directory . '/app-*.log') as $file) {
            if (!is_link($file) && preg_match('/^app-(\d{4}-\d{2}-\d{2})\.log$/D', basename($file), $match) === 1 && $match[1] < $cutoff) {
                if (unlink($file)) {
                    ++$removed;
                }
            }
        }

        return $removed;
    }
}
