<?php

declare(strict_types=1);

namespace App\Integrations\Ai\Llm;

use RuntimeException;

/**
 * A provider call failed. `reason` is a short stable code (never a provider message, which may echo the prompt or the key):
 * `not_configured`, `credentials`, `rate_limited`, `unavailable`, `timeout`, `bad_request`, `bad_response`, `refused`, `forbidden_url`.
 */
final class LlmException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('LLM call failed: ' . $reason);
    }
}
