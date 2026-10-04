<?php

declare(strict_types=1);

namespace App\Integrations\OAuth;

use App\Kernel\HttpClient\HttpClientInterface;

/**
 * VK ID (id.vk.com, OAuth 2.1 with PKCE). Only the profile scopes are requested; rights to post on
 * community walls are a separate connection made when a channel is added (stage 08).
 *
 * VK does not state whether the returned email is confirmed, so it is never treated as verified.
 */
final class VkIdProvider extends AbstractHttpProvider
{
    public function __construct(HttpClientInterface $http, private readonly string $clientId, private readonly string $clientSecret)
    {
        parent::__construct($http);
    }

    public function id(): string
    {
        return 'vkid';
    }

    public function label(): string
    {
        return 'VK ID';
    }

    public function authorizationHost(): string
    {
        return 'id.vk.com';
    }

    public function authorizationUrl(string $redirectUri, string $state, Pkce $pkce, string $nonce): string
    {
        return 'https://id.vk.com/authorize?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'code_challenge' => $pkce->challenge,
            'code_challenge_method' => 'S256',
            'scope' => 'vkid.personal_info email',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeCode(string $code, string $codeVerifier, string $redirectUri, array $callback): TokenSet
    {
        $deviceId = $callback['device_id'] ?? '';
        if ($deviceId === '') {
            throw new OAuthException('vkid: device_id is missing in the callback');
        }
        $form = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $codeVerifier,
            'redirect_uri' => $redirectUri,
            'client_id' => $this->clientId,
            'device_id' => $deviceId,
            'state' => $callback['state'] ?? '',
        ];
        if ($this->clientSecret !== '') {
            $form['client_secret'] = $this->clientSecret;
        }
        $data = $this->postForm('https://id.vk.com/oauth2/auth', $form);
        $token = self::str($data, 'access_token');
        if ($token === null) {
            throw new OAuthException('vkid: no access_token in the reply');
        }

        return new TokenSet($token, self::str($data, 'id_token'), ['user_id' => self::str($data, 'user_id')]);
    }

    public function fetchProfile(TokenSet $tokens, string $nonce): SocialProfile
    {
        $data = $this->postForm('https://id.vk.com/oauth2/user_info', ['client_id' => $this->clientId, 'access_token' => $tokens->accessToken]);
        $user = is_array($data['user'] ?? null) ? $data['user'] : [];
        $id = self::str($user, 'user_id') ?? (is_string($tokens->extra['user_id'] ?? null) ? $tokens->extra['user_id'] : null);
        if ($id === null) {
            throw new OAuthException('vkid: no user id in the profile');
        }
        $name = trim((self::str($user, 'first_name') ?? '') . ' ' . (self::str($user, 'last_name') ?? ''));

        return new SocialProfile('vkid', $id, self::str($user, 'email'), false, $name, self::str($user, 'avatar'));
    }
}
