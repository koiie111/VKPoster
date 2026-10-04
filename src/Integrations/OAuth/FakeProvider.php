<?php

declare(strict_types=1);

namespace App\Integrations\OAuth;

/**
 * Stand-in provider for local development and tests (never available in production). The "authorization
 * page" is `/dev/oauth/fake`, where a developer types the profile; the `code` it produces carries the
 * profile and the PKCE challenge, and `exchangeCode()` checks the verifier like a real server would.
 */
final class FakeProvider implements OAuthProvider
{
    public function __construct(private readonly string $appUrl)
    {
    }

    public function id(): string
    {
        return 'fake';
    }

    public function label(): string
    {
        return 'Тестовый вход';
    }

    public function authorizationHost(): string
    {
        return strtolower((string) parse_url($this->appUrl, PHP_URL_HOST));
    }

    public function authorizationUrl(string $redirectUri, string $state, Pkce $pkce, string $nonce): string
    {
        return rtrim($this->appUrl, '/') . '/dev/oauth/fake?' . http_build_query(['state' => $state, 'challenge' => $pkce->challenge, 'nonce' => $nonce], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Build the code a fake authorization server would hand out.
     *
     * @param array<string, mixed> $profile see `SocialProfile::toArray()` (provider is forced to `fake`)
     */
    public static function makeCode(array $profile, string $challenge, string $nonce): string
    {
        $profile['provider'] = 'fake';

        return 'fake.' . Pkce::base64Url(json_encode(['profile' => $profile, 'challenge' => $challenge, 'nonce' => $nonce], JSON_THROW_ON_ERROR));
    }

    public function exchangeCode(string $code, string $codeVerifier, string $redirectUri, array $callback): TokenSet
    {
        $payload = str_starts_with($code, 'fake.') ? base64_decode(strtr(substr($code, 5), '-_', '+/'), true) : false;
        $data = $payload === false ? null : json_decode($payload, true);
        if (!is_array($data) || !is_string($data['challenge'] ?? null) || !is_array($data['profile'] ?? null)) {
            throw new OAuthException('fake: invalid code');
        }
        if (!hash_equals($data['challenge'], Pkce::challengeFor($codeVerifier))) {
            throw new OAuthException('fake: PKCE verifier does not match the challenge');
        }

        return new TokenSet('fake-token', null, ['profile' => $data['profile'], 'nonce' => $data['nonce'] ?? null]);
    }

    public function fetchProfile(TokenSet $tokens, string $nonce): SocialProfile
    {
        $profile = is_array($tokens->extra['profile'] ?? null) ? SocialProfile::fromArray($tokens->extra['profile']) : null;
        $sent = $tokens->extra['nonce'] ?? null;
        if ($profile === null || !is_string($sent) || !hash_equals($nonce, $sent)) {
            throw new OAuthException('fake: invalid profile or nonce');
        }

        return $profile;
    }
}
