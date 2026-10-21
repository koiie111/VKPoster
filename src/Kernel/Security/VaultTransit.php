<?php

declare(strict_types=1);

namespace App\Kernel\Security;

use App\Kernel\Exception\CryptoException;
use App\Kernel\HttpClient\HttpClientInterface;

/** Non-exportable derived AES-GCM/HMAC operations through the existing HTTP boundary; no retry/fallback. */
final class VaultTransit implements IsolatedCrypto
{
    /** @var array<string, int> */
    private array $versions = [];
    private const PURPOSES = ['application' => 'app', 'webhook' => 'webhook', 'signer' => 'links', 'pdf' => 'pdf', 'password' => 'password', 'clicks' => 'clicks'];

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $endpoint,
        private readonly string $keyName,
        private readonly string $tokenFile,
        private readonly string $caFile = '',
        private readonly string $legacyKeyName = '',
    ) {
        self::validateConfiguration($endpoint, $keyName, $tokenFile, $caFile);
    }

    /** Validate configuration at production startup without contacting the network or returning keys. */
    public static function validateConfiguration(string $endpoint, string $keyName, string $tokenFile, string $caFile): void
    {
        $url = parse_url($endpoint);
        if (!is_array($url) || ($url['scheme'] ?? '') !== 'https' || !isset($url['host'])
            || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
            || preg_match('/^[a-zA-Z0-9_-]{1,48}$/D', $keyName) !== 1) {
            throw new CryptoException('Invalid isolated crypto configuration.');
        }
        clearstatcache(true, $tokenFile);
        if (!is_file($tokenFile) || is_link($tokenFile) || !is_readable($tokenFile)
            || !in_array(fileperms($tokenFile) & 0777, [0400, 0600], true)
            || filesize($tokenFile) < 1 || filesize($tokenFile) > 4096
            || ($caFile !== '' && (!is_file($caFile) || !is_readable($caFile)))) {
            throw new CryptoException('Isolated crypto capability/trust configuration unavailable.');
        }
    }

    public function encrypt(#[\SensitiveParameter] string $plaintext, string $purpose = 'application'): string
    {
        $this->version($purpose);
        $data = $this->call('POST', 'encrypt/' . $this->key($purpose), ['plaintext' => base64_encode($plaintext), 'context' => base64_encode('vkposter:' . $purpose)]);
        $cipher = $data['ciphertext'] ?? null;
        if (!is_string($cipher) || preg_match('/^vault:v[1-9][0-9]*:[A-Za-z0-9+\/=]+$/D', $cipher) !== 1) {
            throw new CryptoException('Invalid isolated crypto response.');
        }
        return $cipher;
    }

    public function decrypt(string $ciphertext, string $purpose = 'application'): string
    {
        $this->version($purpose);
        if (preg_match('/^vault:v[1-9][0-9]*:[A-Za-z0-9+\/=]+$/D', $ciphertext) !== 1) {
            throw new CryptoException('Invalid isolated ciphertext.');
        }
        $data = $this->call('POST', 'decrypt/' . $this->key($purpose), ['ciphertext' => $ciphertext, 'context' => base64_encode('vkposter:' . $purpose)]);
        $plain = isset($data['plaintext']) && is_string($data['plaintext']) ? base64_decode($data['plaintext'], true) : false;
        return $plain === false ? throw new CryptoException('Invalid isolated crypto response.') : $plain;
    }

    /** Explicit read-only compatibility with the earlier v2 Transit key/context. */
    public function decryptLegacy(string $ciphertext): string
    {
        if (preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $this->legacyKeyName) !== 1
            || preg_match('/^vault:v[1-9][0-9]*:[A-Za-z0-9+\/=]+$/D', $ciphertext) !== 1) {
            throw new CryptoException('Legacy isolated crypto key is not configured.');
        }
        $row = $this->call('POST', 'decrypt/' . $this->legacyKeyName, [
            'ciphertext' => $ciphertext, 'context' => base64_encode('vkposter:secrets'),
        ]);
        $plain = is_string($row['plaintext'] ?? null) ? base64_decode($row['plaintext'], true) : false;
        return $plain === false ? throw new CryptoException('Invalid isolated crypto response.') : $plain;
    }

    public function mac(string $purpose, #[\SensitiveParameter] string $data, ?int $version = null): string
    {
        $token = $this->macToken($purpose, $data, $version);
        $encoded = explode(':', $token, 3)[2];
        $binary = preg_match('/^[a-f0-9]{64}$/D', $encoded) === 1 ? hex2bin($encoded) : base64_decode($encoded, true);
        return $binary === false || strlen($binary) !== 32 ? throw new CryptoException('Invalid isolated signing response.') : $binary;
    }

    public function macToken(string $purpose, #[\SensitiveParameter] string $data, ?int $version = null): string
    {
        $current = $this->version($purpose);
        if ($version !== null && ($version < 1 || $version > $current)) {
            throw new CryptoException('Invalid cryptographic key version.');
        }
        $row = $this->call('POST', 'hmac/' . $this->key($purpose) . '/sha2-256', [
            'input' => base64_encode($data), 'context' => base64_encode('vkposter:' . $purpose),
            'key_version' => $version ?? $current,
        ]);
        $hmac = $row['hmac'] ?? null;
        if (!is_string($hmac) || preg_match('/^vault:v[1-9][0-9]*:(?:[a-f0-9]{64}|[A-Za-z0-9+\/]{43}=)$/D', $hmac) !== 1) {
            throw new CryptoException('Invalid isolated signing response.');
        }
        return $hmac;
    }

    public function verifyMac(string $purpose, #[\SensitiveParameter] string $data, string $token): bool
    {
        if (preg_match('/^vault:v[1-9][0-9]*:(?:[a-f0-9]{64}|[A-Za-z0-9+\/]{43}=)$/D', $token) !== 1) {
            return false;
        }
        $this->version($purpose);
        $row = $this->call('POST', 'verify/' . $this->key($purpose) . '/sha2-256', [
            'input' => base64_encode($data), 'context' => base64_encode('vkposter:' . $purpose), 'hmac' => $token,
        ]);
        return ($row['valid'] ?? false) === true;
    }

    public function needsRotation(string $ciphertext, string $purpose = 'application'): bool
    {
        return !str_starts_with($ciphertext, 'vault:v' . $this->version($purpose) . ':');
    }

    public function rewrap(string $ciphertext, string $purpose = 'application'): string
    {
        $this->version($purpose);
        $row = $this->call('POST', 'rewrap/' . $this->key($purpose), [
            'ciphertext' => $ciphertext, 'context' => base64_encode('vkposter:' . $purpose),
        ]);
        $value = $row['ciphertext'] ?? null;
        if (!is_string($value) || preg_match('/^vault:v[1-9][0-9]*:[A-Za-z0-9+\/=]+$/D', $value) !== 1) {
            throw new CryptoException('Invalid isolated crypto response.');
        }
        return $value;
    }

    public function version(string $purpose): int
    {
        if (!isset($this->versions[$purpose])) {
            $data = $this->call('GET', 'keys/' . $this->key($purpose));
            if (($data['type'] ?? '') !== 'aes256-gcm96' || ($data['derived'] ?? false) !== true
                || ($data['exportable'] ?? true) !== false || ($data['allow_plaintext_backup'] ?? true) !== false
                || ($data['convergent_encryption'] ?? true) !== false || !is_int($data['latest_version'] ?? null) || $data['latest_version'] < 1) {
                throw new CryptoException('Isolated crypto key policy is unsafe.');
            }
            $this->versions[$purpose] = $data['latest_version'];
        }
        return $this->versions[$purpose];
    }

    private function key(string $purpose): string
    {
        $suffix = self::PURPOSES[$purpose] ?? throw new CryptoException('Unsupported cryptographic purpose.');
        return $this->keyName . '-' . $suffix;
    }

    /**
     * @param array<string, string|int> $payload
     * @return array<string, mixed>
     */
    private function call(string $method, string $path, #[\SensitiveParameter] array $payload = []): array
    {
        try {
            clearstatcache(true, $this->tokenFile);
            if (!is_file($this->tokenFile) || is_link($this->tokenFile) || !in_array(fileperms($this->tokenFile) & 0777, [0400, 0600], true)) {
                throw new \RuntimeException('private token required');
            }
            $token = trim((string) file_get_contents($this->tokenFile, false, null, 0, 4097));
            if ($token === '' || strlen($token) > 4096 || preg_match('/[\x00-\x20\x7f]/', $token) === 1) {
                throw new \RuntimeException('invalid token');
            }
            $options = ['headers' => ['X-Vault-Token' => $token], 'timeout' => 5, 'connect_timeout' => 2,
                'allow_redirects' => false, 'follow_redirects' => false, 'http_errors' => false, 'verify' => $this->caFile === '' ? true : $this->caFile];
            if ($method === 'POST') {
                $options['json'] = $payload;
            }
            $response = $this->http->request($method, rtrim($this->endpoint, '/') . '/v1/transit/' . $path, $options);
            $body = $response->getBody()->read(2 * 1024 * 1024 + 1);
            if ($response->getStatusCode() !== 200 || strlen($body) > 2 * 1024 * 1024) {
                throw new \RuntimeException('remote rejected');
            }
            $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($data) || !is_array($data['data'] ?? null)) {
                throw new \RuntimeException('invalid response');
            }
            return $data['data'];
        } catch (\Throwable) {
            // No provider message, request, token, plaintext, endpoint or previous exception escapes.
            throw new \App\Kernel\Exception\CryptoUnavailableException('Isolated cryptographic service unavailable.');
        }
    }
}
