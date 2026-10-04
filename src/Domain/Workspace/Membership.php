<?php

declare(strict_types=1);

namespace App\Domain\Workspace;

use DateTimeImmutable;

/**
 * One person's membership in one workspace. `publicId` (ULID) identifies the member in URLs of that workspace.
 */
final class Membership
{
    public function __construct(
        public readonly int $workspaceId,
        public readonly int $userId,
        public readonly string $publicId,
        public readonly Role $role,
        public readonly ?int $invitedBy,
        public readonly DateTimeImmutable $joinedAt,
    ) {
    }
}
