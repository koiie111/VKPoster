<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Integrations\OAuth\Pkce;

/**
 * Makes RS256 tokens and a matching JWKS document, standing in for Google in tests.
 */
final class JwtFactory
{
    public readonly string $kid;
    private \OpenSSLAsymmetricKey $key;

    public function __construct(string $kid = 'test-key-1', ?\OpenSSLAsymmetricKey $key = null)
    {
        $this->kid = $kid;
        $made = $key ?? openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        if ($made === false) {
            throw new \RuntimeException('Cannot create an RSA key.');
        }
        $this->key = $made;
    }

    public function jwks(): string
    {
        $details = openssl_pkey_get_details($this->key);
        if ($details === false || !isset($details['rsa'])) {
            throw new \RuntimeException('No RSA details.');
        }

        return json_encode(['keys' => [[
            'kty' => 'RSA',
            'alg' => 'RS256',
            'use' => 'sig',
            'kid' => $this->kid,
            'n' => Pkce::base64Url($details['rsa']['n']),
            'e' => Pkce::base64Url($details['rsa']['e']),
        ]]], JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $claims
     * @param array<string, mixed> $headerOverrides
     */
    public function token(array $claims, array $headerOverrides = []): string
    {
        $header = $headerOverrides + ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $this->kid];
        $signingInput = Pkce::base64Url(json_encode($header, JSON_THROW_ON_ERROR)) . '.' . Pkce::base64Url(json_encode($claims, JSON_THROW_ON_ERROR));
        openssl_sign($signingInput, $signature, $this->key, OPENSSL_ALGO_SHA256);

        return $signingInput . '.' . Pkce::base64Url($signature);
    }
}
