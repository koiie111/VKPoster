<?php

declare(strict_types=1);

namespace App\Domain\User;

use DateTimeImmutable;

/**
 * A registered person. `email` and `passwordHash` are null for accounts created through social login.
 * The TOTP secret stays encrypted here; only `TwoFactorService` decrypts it.
 */
final class User
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_BLOCKED = 'blocked';

    public function __construct(
        public readonly int $id,
        public readonly ?string $email,
        public readonly ?DateTimeImmutable $emailVerifiedAt,
        public readonly ?string $passwordHash,
        public readonly string $name,
        public readonly string $locale,
        public readonly string $timezone,
        public readonly ?string $totpSecretEnc,
        public readonly ?DateTimeImmutable $totpEnabledAt,
        public readonly bool $isSuperadmin,
        public readonly string $status,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }

    public function isVerified(): bool
    {
        return $this->emailVerifiedAt !== null;
    }

    public function hasTwoFactor(): bool
    {
        return $this->totpEnabledAt !== null && $this->totpSecretEnc !== null;
    }

    public function isBlocked(): bool
    {
        return $this->status === self::STATUS_BLOCKED;
    }
}
