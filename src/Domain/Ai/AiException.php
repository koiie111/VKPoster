<?php

declare(strict_types=1);

namespace App\Domain\Ai;

use RuntimeException;

/**
 * The assistant could not answer. The message is written for the person (Russian, no provider internals); `reason` is the stable code
 * (`not_connected`, `no_credits`, `rate_limited`, `empty`, `too_long`, `provider`, `credentials`, `refused`, `forbidden`).
 */
final class AiException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly bool $upgrade = false)
    {
        parent::__construct($message);
    }
}
