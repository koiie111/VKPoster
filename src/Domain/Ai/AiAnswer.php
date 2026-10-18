<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/** What the editor gets back: text to show (never HTML), what it cost, and whether it still exceeds the requested length. */
final class AiAnswer
{
    public function __construct(
        public readonly string $text,
        public readonly int $credits,
        public readonly bool $overLimit,
        public readonly bool $ownKey,
        /** Where the writing style came from: `channel`, `workspace` or `none`. */
        public readonly string $style = 'none',
    ) {
    }
}
