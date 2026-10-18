<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

/** Bounded plain-text report excerpts: an ellipsis signals omission, never an unfinished word. */
final class ReportExcerpt
{
    public static function fromText(string $text): string
    {
        if (mb_strlen($text) <= 200) {
            return trim($text);
        }
        // The query reads one extra character so an exactly 200-character post is not shortened.
        $head = mb_substr($text, 0, 199);
        $boundary = preg_replace('/\s+\S*$/u', '', $head) ?? '';
        // A single oversized token has no safe word boundary; do not present a misleading fragment.
        if ($boundary === $head) {
            return '…';
        }

        return rtrim($boundary) . '…';
    }
}
