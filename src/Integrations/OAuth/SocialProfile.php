<?php

declare(strict_types=1);

namespace App\Integrations\OAuth;

/**
 * Who a provider says the visitor is. `emailVerified` is true only when the provider itself guarantees
 * that the person controls `email`; an unverified address must never be used to find or merge accounts.
 */
final class SocialProfile
{
    public function __construct(
        public readonly string $provider,
        public readonly string $id,
        public readonly ?string $email,
        public readonly bool $emailVerified,
        public readonly string $name,
        public readonly ?string $avatar = null,
    ) {
    }

    /**
     * The address we may trust (lower-cased), or null.
     */
    public function trustedEmail(): ?string
    {
        return $this->emailVerified && $this->email !== null && $this->email !== '' ? mb_strtolower(trim($this->email)) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'id' => $this->id,
            'email' => $this->email,
            'email_verified' => $this->emailVerified,
            'name' => $this->name,
            'avatar' => $this->avatar,
        ];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        if (!is_string($data['provider'] ?? null) || !is_string($data['id'] ?? null) || !is_string($data['name'] ?? null)) {
            return null;
        }

        return new self(
            $data['provider'],
            $data['id'],
            is_string($data['email'] ?? null) ? $data['email'] : null,
            ($data['email_verified'] ?? false) === true,
            $data['name'],
            is_string($data['avatar'] ?? null) ? $data['avatar'] : null,
        );
    }
}
