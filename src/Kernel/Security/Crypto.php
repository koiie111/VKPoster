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
    public function __construct(private readonly string $currentKeyId, array $keys)
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
        if (!isset($this->keys[$currentKeyId])) {
            throw new CryptoException('Current encryption key is not configured.');
        }
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $this->keys[$this->currentKeyId]);

        return self::VERSION . ':' . $this->currentKeyId . ':' . base64_encode($nonce . $cipher);
    }

    /**
     * @throws CryptoException when the payload is malformed, the key is unknown, or it was tampered with
     */
    public function decrypt(string $payload): string
    {
        $parts = explode(':', $payload, 3);
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
    public function needsRotation(string $payload): bool
    {
        $parts = explode(':', $payload, 3);

        return ($parts[1] ?? '') !== $this->currentKeyId;
    }

    /**
     * Re-encrypt with the current key.
     *
     * @throws CryptoException
     */
    public function rotate(string $payload): string
    {
        return $this->encrypt($this->decrypt($payload));
    }

    /**
     * Raw bytes of the current key, for deriving sub-keys (see `Signer`).
     */
    public function deriveKey(string $purpose): string
    {
        return hash_hkdf('sha256', $this->keys[$this->currentKeyId], 32, $purpose);
    }
}
