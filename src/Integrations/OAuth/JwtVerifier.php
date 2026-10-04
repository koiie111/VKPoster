<?php

declare(strict_types=1);

namespace App\Integrations\OAuth;

use App\Kernel\HttpClient\HttpClientInterface;
use App\Support\Clock;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Verifies an OpenID Connect `id_token` signed with RS256: signature against the provider's JWKS, then
 * `iss`, `aud`, `exp` and `nonce`. Only RS256 is accepted (never `none` or an HMAC algorithm, which would
 * let an attacker sign with a public key). The JWK is turned into a PEM key by hand to avoid a JWT library
 * for this single use (see docs/adr/0004-oauth-providers.md).
 */
final class JwtVerifier
{
    private const LEEWAY = 60;

    public function __construct(private readonly HttpClientInterface $http, private readonly Clock $clock)
    {
    }

    /**
     * @param list<string> $issuers accepted `iss` values
     * @return array<string, mixed> the verified claims
     * @throws OAuthException
     */
    public function verify(string $jwt, string $jwksUrl, array $issuers, string $audience, string $nonce): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new OAuthException('id_token: malformed');
        }
        [$h, $p, $s] = $parts;
        $header = self::decodeJson($h);
        $claims = self::decodeJson($p);
        $signature = self::base64UrlDecode($s);
        if (($header['alg'] ?? null) !== 'RS256' || !is_string($header['kid'] ?? null) || $signature === '') {
            throw new OAuthException('id_token: unsupported algorithm or missing kid');
        }
        $pem = $this->publicKey($jwksUrl, $header['kid']);
        if (openssl_verify($h . '.' . $p, $signature, $pem, OPENSSL_ALGO_SHA256) !== 1) {
            throw new OAuthException('id_token: bad signature');
        }
        if (!in_array($claims['iss'] ?? null, $issuers, true)) {
            throw new OAuthException('id_token: wrong issuer');
        }
        $aud = $claims['aud'] ?? null;
        if (!(is_string($aud) && hash_equals($audience, $aud)) && !(is_array($aud) && in_array($audience, $aud, true))) {
            throw new OAuthException('id_token: wrong audience');
        }
        $now = $this->clock->now()->getTimestamp();
        $exp = $claims['exp'] ?? null;
        if (!is_int($exp) || $exp + self::LEEWAY < $now) {
            throw new OAuthException('id_token: expired');
        }
        $iat = $claims['iat'] ?? null;
        if (is_int($iat) && $iat > $now + self::LEEWAY) {
            throw new OAuthException('id_token: issued in the future');
        }
        $tokenNonce = $claims['nonce'] ?? null;
        if (!is_string($tokenNonce) || $nonce === '' || !hash_equals($nonce, $tokenNonce)) {
            throw new OAuthException('id_token: wrong nonce');
        }

        return $claims;
    }

    private function publicKey(string $jwksUrl, string $kid): string
    {
        try {
            $response = $this->http->request('GET', $jwksUrl, ['headers' => ['Accept' => 'application/json']]);
        } catch (GuzzleException $e) {
            throw new OAuthException('jwks: transport error', 0, $e);
        }
        $jwks = json_decode((string) $response->getBody(), true);
        $keys = is_array($jwks) && is_array($jwks['keys'] ?? null) ? $jwks['keys'] : [];
        foreach ($keys as $jwk) {
            if (is_array($jwk) && ($jwk['kid'] ?? null) === $kid && ($jwk['kty'] ?? null) === 'RSA' && is_string($jwk['n'] ?? null) && is_string($jwk['e'] ?? null)) {
                return self::pem(self::base64UrlDecode($jwk['n']), self::base64UrlDecode($jwk['e']));
            }
        }

        throw new OAuthException('jwks: key not found');
    }

    /**
     * RSA public key (modulus, exponent) as a SubjectPublicKeyInfo PEM.
     */
    public static function pem(string $modulus, string $exponent): string
    {
        $rsaKey = self::der(0x30, self::derInteger($modulus) . self::derInteger($exponent));
        $algorithm = self::der(0x30, self::der(0x06, "\x2A\x86\x48\x86\xF7\x0D\x01\x01\x01") . "\x05\x00");
        $spki = self::der(0x30, $algorithm . self::der(0x03, "\x00" . $rsaKey));

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '' || (ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }

        return self::der(0x02, $bytes);
    }

    private static function der(int $tag, string $body): string
    {
        $length = strlen($body);
        if ($length < 128) {
            return chr($tag) . chr($length) . $body;
        }
        $encoded = ltrim(pack('N', $length), "\x00");

        return chr($tag) . chr(0x80 | strlen($encoded)) . $encoded . $body;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeJson(string $segment): array
    {
        $data = json_decode(self::base64UrlDecode($segment), true);

        return is_array($data) ? $data : throw new OAuthException('id_token: bad JSON');
    }

    private static function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? '' : $decoded;
    }
}
