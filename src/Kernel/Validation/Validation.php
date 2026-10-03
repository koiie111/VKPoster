<?php

declare(strict_types=1);

namespace App\Kernel\Validation;

/**
 * Result of validating one input array: errors per field and the subset of data that has rules.
 */
final class Validation
{
    /**
     * @param array<string, list<string>> $errors
     * @param array<string, mixed> $validated
     */
    public function __construct(private readonly array $errors, private readonly array $validated)
    {
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function first(string $field): ?string
    {
        return $this->errors[$field][0] ?? null;
    }

    /**
     * Only fields that have rules (nothing extra from the request leaks in).
     *
     * @return array<string, mixed>
     * @throws ValidationException
     */
    public function validated(): array
    {
        if ($this->errors !== []) {
            throw new ValidationException($this->errors);
        }

        return $this->validated;
    }
}
