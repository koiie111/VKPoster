<?php

declare(strict_types=1);

namespace App\Integrations\Ai\Llm;

/** A model's answer as plain text plus the token counts the provider reported (0 when it did not). */
final class LlmResult
{
    public function __construct(
        public readonly string $text,
        public readonly string $model,
        public readonly int $tokensIn = 0,
        public readonly int $tokensOut = 0,
    ) {
    }
}
