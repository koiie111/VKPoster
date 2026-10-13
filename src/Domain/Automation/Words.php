<?php

declare(strict_types=1);

namespace App\Domain\Automation;

/**
 * Splitting free text typed by a person (lists of times, channels, links) into non-empty pieces.
 */
final class Words
{
    /**
     * @param non-empty-string $pattern a regular expression (with the `u` flag) for the separators
     * @return list<string>
     */
    public static function split(string $pattern, string $text): array
    {
        $parts = preg_split($pattern, $text, -1, PREG_SPLIT_NO_EMPTY);

        return $parts === false ? [] : $parts;
    }
}
