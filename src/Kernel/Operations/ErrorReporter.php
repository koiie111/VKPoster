<?php

declare(strict_types=1);

namespace App\Kernel\Operations;

/** Minimal error boundary: transports must never expose raw requests, exception messages or credentials. */
interface ErrorReporter
{
    /** @param array<array-key, mixed> $context */
    public function report(string $event, array $context): void;
}
