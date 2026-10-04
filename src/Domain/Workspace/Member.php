<?php

declare(strict_types=1);

namespace App\Domain\Workspace;

use DateTimeImmutable;

/**
 * A workspace member as shown in the team list: membership data plus the person's name and email.
 */
final class Member
{
    public function __construct(
        public readonly string $publicId,
        public readonly int $userId,
        public readonly string $name,
        public readonly ?string $email,
        public readonly Role $role,
        public readonly bool $channelsRestricted,
        public readonly DateTimeImmutable $joinedAt,
    ) {
    }
}
