<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Kernel\Security\IsolatedCrypto;
use App\Kernel\Exception\CryptoException;

/** Test-only DI replacement. Never available to production configuration or autoload. */
final class FakeIsolatedCrypto implements IsolatedCrypto
{
    public function encrypt(string $plaintext, string $purpose = 'application'): string
    {
        return 'vault:v1:' . base64_encode($purpose . ':' . $plaintext);
    }
    public function decrypt(string $ciphertext, string $purpose = 'application'): string
    {
        $raw = base64_decode(explode(':', $ciphertext, 3)[2] ?? '', true);
        $prefix = $purpose . ':';
        if (!is_string($raw) || !str_starts_with($raw, $prefix)) {
            throw new CryptoException('Synthetic ciphertext mismatch.');
        }
        return substr($raw, strlen($prefix));
    }
    public function decryptLegacy(string $ciphertext): string
    {
        return $this->decrypt($ciphertext);
    }
    public function mac(string $purpose, string $data, ?int $version = null): string
    {
        return hash_hmac('sha256', $data, 'synthetic-only-' . $purpose . ($version ?? 1), true);
    }
    public function macToken(string $purpose, string $data, ?int $version = null): string
    {
        return 'vault:v' . ($version ?? 1) . ':' . bin2hex($this->mac($purpose, $data, $version));
    }
    public function verifyMac(string $purpose, string $data, string $token): bool
    {
        $parts = explode(':', $token, 3);
        return count($parts) === 3 && hash_equals($this->macToken($purpose, $data, (int) substr($parts[1], 1)), $token);
    }
    public function needsRotation(string $ciphertext, string $purpose = 'application'): bool
    {
        return !str_starts_with($ciphertext, 'vault:v1:');
    }
    public function rewrap(string $ciphertext, string $purpose = 'application'): string
    {
        return $this->encrypt($this->decrypt($ciphertext, $purpose), $purpose);
    }
    public function version(string $purpose): int
    {
        return 1;
    }
}
