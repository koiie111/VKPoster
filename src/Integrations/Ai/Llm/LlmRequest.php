<?php

declare(strict_types=1);

namespace App\Integrations\Ai\Llm;

/**
 * One chat turn for a language model: trusted instructions (`system`) and untrusted material (`user`, always wrapped as data by the
 * caller). The output is bounded by `maxTokens`.
 */
final class LlmRequest
{
    public function __construct(
        public readonly string $system,
        public readonly string $user,
        public readonly int $maxTokens = 1024,
        public readonly float $temperature = 0.7,
    ) {
    }
}
