<?php

declare(strict_types=1);

namespace App\Kernel\Exception;

use RuntimeException;

/**
 * An error that maps directly to an HTTP status (404, 405, 419, 429, ...). Thrown anywhere in the
 * request path and rendered by the `ErrorHandler` middleware.
 */
final class HttpException extends RuntimeException
{
    /**
     * @param array<string, string> $headers extra response headers (e.g. `Allow`, `Retry-After`)
     */
    public function __construct(public readonly int $status, string $message = '', public readonly array $headers = [])
    {
        parent::__construct($message !== '' ? $message : 'HTTP ' . $status, $status);
    }
}
