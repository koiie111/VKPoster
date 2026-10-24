<?php

declare(strict_types=1);

namespace App\Kernel\Security;

/** Non-exporting, purpose-isolated remote cryptography. No method returns key material. */
interface IsolatedCrypto
{
    public function encrypt(string $plaintext, string $purpose = 'application'): string;
    public function decrypt(string $ciphertext, string $purpose = 'application'): string;
    public function decryptLegacy(string $ciphertext): string;
    public function mac(string $purpose, string $data, ?int $version = null): string;
    public function macToken(string $purpose, string $data, ?int $version = null): string;
    public function verifyMac(string $purpose, string $data, string $token): bool;
    public function verifyLegacyMac(string $data, string $signature): bool;
    public function needsRotation(string $ciphertext, string $purpose = 'application'): bool;
    public function rewrap(string $ciphertext, string $purpose = 'application'): string;
    public function version(string $purpose, bool $refresh = false): int;
}
