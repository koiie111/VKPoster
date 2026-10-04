<?php

declare(strict_types=1);

namespace App\Domain\Auth\Social;

use DateTimeImmutable;

/**
 * A sign-in method that belongs to a user: an account at an outside provider (VK ID, Yandex, Google, Telegram).
 */
final class UserIdentity
{
    public function __construct(
        public readonly int $id,
        public readonly int $userId,
        public readonly string $provider,
        public readonly string $providerUserId,
        public readonly ?string $email,
        public readonly ?string $displayName,
        public readonly DateTimeImmutable $linkedAt,
    ) {
    }
}
