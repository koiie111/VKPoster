<?php

declare(strict_types=1);

namespace App\Domain\Api;

/** Safe public API error; never includes credentials, provider responses or internal exception text. */
final class ApiException extends \RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
