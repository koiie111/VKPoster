<?php

declare(strict_types=1);

namespace App\Kernel\Security;

/**
 * Outcome of a rate-limit check.
 */
final class RateLimitResult
{
    public function __construct(
        public readonly bool $allowed,
        public readonly int $remaining,
        public readonly int $retryAfter,
    ) {
    }
}
