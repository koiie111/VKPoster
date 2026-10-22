<?php

declare(strict_types=1);

namespace App\Kernel\Security;

use App\Kernel\Exception\CryptoException;

/**
 * Authenticated encryption for secrets stored in the database (social tokens, TOTP seeds).
 *
 * libsodium `crypto_secretbox` (XSalsa20-Poly1305). Ciphertext format: `v1:<key_id>:<base64(nonce|cipher)>`.
 * The key id lets old rows stay readable after rotation: new data is always encrypted with the
 * current key, `decrypt()` picks the key named in the ciphertext, `needsRotation()` flags rows to re-encrypt.
 */
final class Crypto
{
    private const VERSION = 'v1';

    /** @var array<string, string> key id => raw 32-byte key */
    private array $keys;

    /**
     * @param string $currentKeyId id written into new ciphertexts
     * @param array<string, string> $keys key id => base64 key (32 bytes decoded), must contain the current id
     * @throws CryptoException
     */
    public function __construct(private readonly string $currentKeyId, #[\SensitiveParameter] array $keys, private readonly ?IsolatedCrypto $isolated = null)
    {
        $this->keys = [];
        foreach ($keys as $id => $encoded) {
            if (preg_match('/^[A-Za-z0-9_-]{1,32}$/', $id) !== 1) {
                throw new CryptoException('Invalid encryption key id.');
            }
            $raw = base64_decode($encoded, true);
            if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                throw new CryptoException(sprintf('Encryption key "%s" must be 32 bytes, base64-encoded.', $id));
            }
            $this->keys[$id] = $raw;
        }
        if (preg_match('/^[A-Za-z0-9_-]{1,32}$/D', $currentKeyId) !== 1 || ($isolated === null && !isset($this->keys[$currentKeyId]))) {
            throw new CryptoException('Current encryption key is not configured.');
        }
    }

    public function encrypt(#[\SensitiveParameter] string $plaintext, string $purpose = 'application'): string
    {
        if ($this->isolated !== null) {
            return 'v3:' . $purpose . ':' . $this->isolated->encrypt($plaintext, $purpose);
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $this->keys[$this->currentKeyId]);

        return self::VERSION . ':' . $this->currentKeyId . ':' . base64_encode($nonce . $cipher);
    }

    /**
     * @throws CryptoException when the payload is malformed, the key is unknown, or it was tampered with
     */
    public function decrypt(string $payload, string $purpose = 'application'): string
    {
        $parts = explode(':', $payload, 3);
        if (count($parts) === 3 && $parts[0] === 'v3' && $this->isolated !== null) {
            if ($parts[1] !== $purpose) {
                throw new CryptoException('Ciphertext purpose mismatch.');
            }
            return $this->isolated->decrypt($parts[2], $purpose);
        }
        if (count($parts) === 3 && $parts[0] === 'v2' && $this->isolated !== null) {
            return $this->isolated->decryptLegacy($parts[2]);
        }
        if (count($parts) !== 3 || $parts[0] !== self::VERSION) {
            throw new CryptoException('Unsupported ciphertext format.');
        }
        $key = $this->keys[$parts[1]] ?? throw new CryptoException('Unknown encryption key id.');
        $raw = base64_decode($parts[2], true);
        if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new CryptoException('Malformed ciphertext.');
        }
        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $key,
        );
        if ($plain === false) {
            throw new CryptoException('Ciphertext could not be authenticated.');
        }

        return $plain;
    }

    /**
     * True when the payload was encrypted with a key other than the current one.
     */
    public function needsRotation(string $payload, string $purpose = 'application'): bool
    {
        $parts = explode(':', $payload, 3);

        if ($this->isolated !== null) {
            return count($parts) !== 3 || $parts[0] !== 'v3' || $parts[1] !== $purpose || $this->isolated->needsRotation($parts[2], $purpose);
        }
        return $parts[0] !== self::VERSION || ($parts[1] ?? '') !== $this->currentKeyId;
    }

    /**
     * Re-encrypt with the current key.
     *
     * @throws CryptoException
     */
    public function rotate(string $payload, string $purpose = 'application'): string
    {
        $parts = explode(':', $payload, 3);
        if ($this->isolated !== null && count($parts) === 3 && $parts[0] === 'v3' && $parts[1] === $purpose) {
            return 'v3:' . $purpose . ':' . $this->isolated->rewrap($parts[2], $purpose);
        }
        return $this->encrypt($this->decrypt($payload, $purpose), $purpose);
    }

    /**
     * Raw bytes of the current key, for deriving sub-keys (see `Signer`).
     */
    public function mac(string $purpose, #[\SensitiveParameter] string $data): string
    {
        return $this->isolated !== null ? $this->isolated->mac($purpose, $data) : hash_hmac('sha256', $data, $this->deriveKey($purpose), true);
    }

    public function isIsolated(): bool
    {
        return $this->isolated !== null;
    }

    public function signature(string $purpose, #[\SensitiveParameter] string $data): string
    {
        return $this->isolated !== null ? $this->isolated->macToken($purpose, $data) : bin2hex($this->mac($purpose, $data));
    }

    public function verifySignature(string $purpose, #[\SensitiveParameter] string $data, string $signature): bool
    {
        return $this->isolated !== null ? $this->isolated->verifyMac($purpose, $data, $signature)
            : hash_equals($this->signature($purpose, $data), $signature);
    }

    /** Verify-only compatibility; old signing keys stay inside Transit, never in normal app PHP. */
    public function verifyLegacySignature(string $data, string $signature): bool
    {
        return $this->isolated?->verifyLegacyMac($data, $signature) ?? false;
    }

    public function deriveKey(string $purpose): string
    {
        if ($this->isolated !== null) {
            throw new CryptoException('Isolated keys cannot be exported.');
        }
        return hash_hkdf('sha256', $this->keys[$this->currentKeyId], 32, $purpose);
    }
}
