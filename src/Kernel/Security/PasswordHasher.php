<?php

declare(strict_types=1);

namespace App\Kernel\Security;

/**
 * Password hashing with Argon2id. `needsRehash()` lets login code upgrade hashes after cost changes.
 */
final class PasswordHasher
{
    public function __construct(
        private readonly int $memoryKib = 65536,
        private readonly int $timeCost = 4,
        private readonly int $threads = 1,
    ) {
    }

    public function hash(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID, $this->options());
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID, $this->options());
    }

    /**
     * @return array{memory_cost: int, time_cost: int, threads: int}
     */
    private function options(): array
    {
        return ['memory_cost' => $this->memoryKib, 'time_cost' => $this->timeCost, 'threads' => $this->threads];
    }
}
