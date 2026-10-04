<?php

declare(strict_types=1);

namespace App\Integrations\Payments\Contracts;

use RuntimeException;

/**
 * The provider could not do what we asked. `$retryable`: a temporary problem (network, 5xx), asking again later may work.
 * The technical message goes to the log; `forUser()` is a Russian sentence for the customer.
 */
final class GatewayException extends RuntimeException
{
    public function __construct(string $message, private readonly string $userMessage = 'Платёжная система сейчас недоступна. Попробуйте чуть позже.', public readonly bool $retryable = false)
    {
        parent::__construct($message);
    }

    public function forUser(): string
    {
        return $this->userMessage;
    }
}
