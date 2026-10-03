<?php

declare(strict_types=1);

namespace App\Kernel\Validation;

use RuntimeException;

/**
 * Thrown by `Validation::validated()` when input is invalid; carries messages per field.
 */
final class ValidationException extends RuntimeException
{
    /**
     * @param array<string, list<string>> $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('The given data was invalid.');
    }
}
