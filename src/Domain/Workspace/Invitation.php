<?php

declare(strict_types=1);

namespace App\Domain\Workspace;

use DateTimeImmutable;

/**
 * An invitation to join a workspace. The emailed token itself is never stored, only its hash.
 */
final class Invitation
{
    public function __construct(
        public readonly int $id,
        public readonly string $publicId,
        public readonly int $workspaceId,
        public readonly string $email,
        public readonly Role $role,
        public readonly ?int $invitedBy,
        public readonly DateTimeImmutable $expiresAt,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }
}
