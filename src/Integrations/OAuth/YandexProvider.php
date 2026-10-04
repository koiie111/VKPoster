<?php

declare(strict_types=1);

namespace App\Integrations\OAuth;

use App\Kernel\HttpClient\HttpClientInterface;

/**
 * Yandex ID (oauth.yandex.ru). Scopes `login:email login:info`. Yandex confirms the mailbox before it
 * becomes the account's default address, so the email counts as verified.
 */
final class YandexProvider extends AbstractHttpProvider
{
    public function __construct(HttpClientInterface $http, private readonly string $clientId, private readonly string $clientSecret)
    {
        parent::__construct($http);
    }

    public function id(): string
    {
        return 'yandex';
    }

    public function label(): string
    {
        return 'Яндекс';
    }

    public function authorizationHost(): string
    {
        return 'oauth.yandex.ru';
    }

    public function authorizationUrl(string $redirectUri, string $state, Pkce $pkce, string $nonce): string
    {
        return 'https://oauth.yandex.ru/authorize?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'code_challenge' => $pkce->challenge,
            'code_challenge_method' => 'S256',
            'scope' => 'login:email login:info',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeCode(string $code, string $codeVerifier, string $redirectUri, array $callback): TokenSet
    {
        $data = $this->postForm('https://oauth.yandex.ru/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $codeVerifier,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]);
        $token = self::str($data, 'access_token');
        if ($token === null) {
            throw new OAuthException('yandex: no access_token in the reply');
        }

        return new TokenSet($token);
    }

    public function fetchProfile(TokenSet $tokens, string $nonce): SocialProfile
    {
        $data = $this->getJson('https://login.yandex.ru/info?format=json', ['Authorization' => 'OAuth ' . $tokens->accessToken]);
        $id = self::str($data, 'id');
        if ($id === null) {
            throw new OAuthException('yandex: no id in the profile');
        }
        $email = self::str($data, 'default_email');
        $name = self::str($data, 'real_name') ?? self::str($data, 'display_name') ?? self::str($data, 'login') ?? '';
        $avatarId = self::str($data, 'default_avatar_id');
        $avatar = $avatarId !== null && ($data['is_avatar_empty'] ?? false) !== true && preg_match('/^[\w.\/-]+$/', $avatarId) === 1
            ? 'https://avatars.yandex.net/get-yapic/' . $avatarId . '/islands-200'
            : null;

        return new SocialProfile('yandex', $id, $email, $email !== null, $name, $avatar);
    }
}
