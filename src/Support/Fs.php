<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Small filesystem helpers that give strict types back for loosely typed PHP functions.
 */
final class Fs
{
    /**
     * Files matching a glob pattern, sorted by name; an empty list when nothing matches.
     *
     * @return list<string>
     */
    public static function glob(string $pattern): array
    {
        $files = glob($pattern);
        if ($files === false) {
            return [];
        }
        sort($files);

        return $files;
    }
}
