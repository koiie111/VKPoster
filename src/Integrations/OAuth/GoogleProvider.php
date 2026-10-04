<?php

declare(strict_types=1);

namespace App\Integrations\OAuth;

use App\Kernel\HttpClient\HttpClientInterface;

/**
 * Google sign-in over OpenID Connect. The profile comes from the signed `id_token` (checked by
 * `JwtVerifier`), so no extra userinfo call is needed. `email_verified` is Google's own claim.
 */
final class GoogleProvider extends AbstractHttpProvider
{
    public const JWKS_URL = 'https://www.googleapis.com/oauth2/v3/certs';

    public function __construct(
        HttpClientInterface $http,
        private readonly JwtVerifier $jwt,
        private readonly string $clientId,
        private readonly string $clientSecret,
    ) {
        parent::__construct($http);
    }

    public function id(): string
    {
        return 'google';
    }

    public function label(): string
    {
        return 'Google';
    }

    public function authorizationHost(): string
    {
        return 'accounts.google.com';
    }

    public function authorizationUrl(string $redirectUri, string $state, Pkce $pkce, string $nonce): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri,
            'scope' => 'openid email profile',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $pkce->challenge,
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeCode(string $code, string $codeVerifier, string $redirectUri, array $callback): TokenSet
    {
        $data = $this->postForm('https://oauth2.googleapis.com/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $codeVerifier,
            'redirect_uri' => $redirectUri,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]);
        $idToken = self::str($data, 'id_token');
        if ($idToken === null) {
            throw new OAuthException('google: no id_token in the reply');
        }

        return new TokenSet(self::str($data, 'access_token') ?? '', $idToken);
    }

    public function fetchProfile(TokenSet $tokens, string $nonce): SocialProfile
    {
        if ($tokens->idToken === null) {
            throw new OAuthException('google: no id_token');
        }
        $claims = $this->jwt->verify($tokens->idToken, self::JWKS_URL, ['https://accounts.google.com', 'accounts.google.com'], $this->clientId, $nonce);
        $sub = self::str($claims, 'sub');
        if ($sub === null) {
            throw new OAuthException('google: no sub claim');
        }
        $verified = ($claims['email_verified'] ?? false) === true || ($claims['email_verified'] ?? '') === 'true';
        $email = self::str($claims, 'email');

        return new SocialProfile('google', $sub, $email, $verified && $email !== null, self::str($claims, 'name') ?? '', self::str($claims, 'picture'));
    }
}
