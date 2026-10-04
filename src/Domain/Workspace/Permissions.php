<?php

declare(strict_types=1);

namespace App\Domain\Workspace;

use InvalidArgumentException;

/**
 * Answers "does this role hold this permission?" from the matrix in `config/permissions.php` (loaded by `Config` as `permissions`).
 * An unknown permission name is a programming error and throws, so a typo in a route or template can
 * never silently grant (or hide) access.
 */
final class Permissions
{
    /** @var array<string, list<string>> */
    private array $matrix;

    /**
     * @param array<string, list<string>> $matrix permission => role values
     */
    public function __construct(array $matrix)
    {
        $this->matrix = $matrix;
    }

    /**
     * @throws InvalidArgumentException for a permission that is not in the matrix
     */
    public function allows(Role $role, string $permission): bool
    {
        if (!isset($this->matrix[$permission])) {
            throw new InvalidArgumentException(sprintf('Unknown permission "%s".', $permission));
        }

        return in_array($role->value, $this->matrix[$permission], true);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->matrix);
    }
}
