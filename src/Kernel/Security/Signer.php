<?php

declare(strict_types=1);

namespace App\Kernel\Security;

use App\Support\Clock;

/**
 * HMAC-SHA256 signatures for opaque values and URLs (media links, guest links, unsubscribe links).
 * The signing key is derived from the application key, so it rotates with it.
 */
final class Signer
{
    public function __construct(private readonly Crypto $crypto, private readonly Clock $clock)
    {
    }

    public function sign(string $data): string
    {
        return $this->crypto->isIsolated() ? 't1_' . $this->base64Url($this->crypto->signature('signer', $data))
            : $this->base64Url($this->crypto->mac('signer', $data));
    }

    public function verify(string $data, string $signature): bool
    {
        if (!$this->crypto->isIsolated()) {
            return hash_equals($this->sign($data), $signature);
        }
        if (preg_match('/^t1_([A-Za-z0-9_-]{16,256})$/D', $signature, $parts) === 1) {
            $native = base64_decode(strtr($parts[1], '-_', '+/'), true);
            return is_string($native) && $this->crypto->verifySignature('signer', $data, $native);
        }
        if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $signature) === 1) {
            return $this->crypto->verifyLegacySignature($data, $signature);
        }
        return false;
    }

    /**
     * Append `expires` (optional) and `signature` query parameters to a URL or relative path.
     */
    public function signUrl(string $url, ?int $ttlSeconds = null): string
    {
        $parts = parse_url($url);
        $query = [];
        parse_str($parts['query'] ?? '', $query);
        if ($ttlSeconds !== null) {
            $query['expires'] = (string) ($this->clock->now()->getTimestamp() + $ttlSeconds);
        }
        $base = self::withQuery($url, $query);
        $query['signature'] = $this->sign($base);

        return self::withQuery($url, $query);
    }

    /**
     * Check signature and, when present, the `expires` timestamp.
     */
    public function verifyUrl(string $url): bool
    {
        $parts = parse_url($url);
        $query = [];
        parse_str($parts['query'] ?? '', $query);
        $signature = $query['signature'] ?? null;
        if (!is_string($signature)) {
            return false;
        }
        unset($query['signature']);
        if (isset($query['expires'])) {
            if (!is_string($query['expires']) || !ctype_digit($query['expires'])
                || (int) $query['expires'] < $this->clock->now()->getTimestamp()) {
                return false;
            }
        }

        return $this->verify(self::withQuery($url, $query), $signature);
    }

    /**
     * @param array<array-key, mixed> $query
     */
    private static function withQuery(string $url, array $query): string
    {
        $base = explode('?', explode('#', $url, 2)[0], 2)[0];
        ksort($query);

        return $query === [] ? $base : $base . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function base64Url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
