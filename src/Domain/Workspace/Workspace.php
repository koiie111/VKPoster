<?php

declare(strict_types=1);

namespace App\Domain\Workspace;

use DateTimeImmutable;

/**
 * A workspace (tenant). `publicId` (ULID) is the only identifier that may appear in URLs.
 */
final class Workspace
{
    public function __construct(
        public readonly int $id,
        public readonly string $publicId,
        public readonly int $ownerId,
        public readonly string $name,
        public readonly string $timezone,
        public readonly string $locale,
        public readonly bool $isPersonal,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }
}
