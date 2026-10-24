<?php

declare(strict_types=1);

namespace App\Kernel\Security;

/**
 * Password hashing with Argon2id. `needsRehash()` lets login code upgrade hashes after cost changes.
 */
final class PasswordHasher
{
    /** @param array<string, string> $previousPeppers Raw 32-byte old keys, only for verification. */
    public function __construct(
        private readonly int $memoryKib = 65536,
        private readonly int $timeCost = 4,
        private readonly int $threads = 1,
        #[\SensitiveParameter] private readonly ?string $pepper = null,
        private readonly string $pepperId = 'p1',
        #[\SensitiveParameter] private readonly array $previousPeppers = [],
        private readonly ?IsolatedCrypto $isolated = null,
    ) {
        if (preg_match('/^[A-Za-z0-9_-]{1,32}$/D', $pepperId) !== 1 || ($pepper !== null && strlen($pepper) !== 32)
            || ($pepper === null && $previousPeppers !== []) || isset($previousPeppers[$pepperId])) {
            throw new \App\Kernel\Exception\ConfigException('Invalid password pepper keyring.');
        }
        foreach ($previousPeppers as $id => $key) {
            if (preg_match('/^[A-Za-z0-9_-]{1,32}$/D', $id) !== 1 || strlen($key) !== 32) {
                throw new \App\Kernel\Exception\ConfigException('Invalid previous password pepper.');
            }
        }
    }

    public function hash(#[\SensitiveParameter] string $password): string
    {
        if ($this->isolated !== null) {
            $version = $this->isolated->version('password');
            $value = bin2hex($this->isolated->mac('password', 'password:' . $password, $version));
            return 'pepper:v2:' . $version . ':' . password_hash($value, PASSWORD_ARGON2ID, $this->options());
        }
        if ($this->pepper === null) {
            return password_hash($password, PASSWORD_ARGON2ID, $this->options());
        }
        return 'pepper:v1:' . $this->pepperId . ':' . password_hash($this->preHash($password, $this->pepper), PASSWORD_ARGON2ID, $this->options());
    }

    public function verify(#[\SensitiveParameter] string $password, string $hash): bool
    {
        if (!str_starts_with($hash, 'pepper:')) {
            return password_verify($password, $hash); // Legacy verifier; successful login upgrades it immediately.
        }
        $parts = explode(':', $hash, 4);
        if (count($parts) === 4 && $parts[1] === 'v2' && ctype_digit($parts[2]) && $this->isolated !== null) {
            $value = bin2hex($this->isolated->mac('password', 'password:' . $password, (int) $parts[2]));
            return password_verify($value, $parts[3]);
        }
        if (count($parts) !== 4 || $parts[1] !== 'v1' || !str_starts_with($parts[3], '$argon2id$')) {
            return false;
        }
        $key = $parts[2] === $this->pepperId ? $this->pepper : ($this->previousPeppers[$parts[2]] ?? null);
        return $key !== null && password_verify($this->preHash($password, $key), $parts[3]);
    }

    public function needsRehash(string $hash): bool
    {
        if (str_starts_with($hash, 'pepper:')) {
            $parts = explode(':', $hash, 4);
            if ($this->isolated !== null) {
                return count($parts) !== 4 || $parts[1] !== 'v2' || $parts[2] !== (string) $this->isolated->version('password')
                    || password_needs_rehash($parts[3], PASSWORD_ARGON2ID, $this->options());
            }
            return count($parts) !== 4 || $parts[1] !== 'v1' || $parts[2] !== $this->pepperId || $this->pepper === null
                || password_needs_rehash($parts[3], PASSWORD_ARGON2ID, $this->options());
        }
        return $this->isolated !== null || $this->pepper !== null || password_needs_rehash($hash, PASSWORD_ARGON2ID, $this->options());
    }

    private function preHash(#[\SensitiveParameter] string $password, #[\SensitiveParameter] string $key): string
    {
        return hash_hmac('sha256', 'password:' . $password, $key);
    }

    /**
     * @return array{memory_cost: int, time_cost: int, threads: int}
     */
    private function options(): array
    {
        return ['memory_cost' => $this->memoryKib, 'time_cost' => $this->timeCost, 'threads' => $this->threads];
    }
}
