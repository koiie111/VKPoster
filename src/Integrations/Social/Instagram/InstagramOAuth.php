<?php

declare(strict_types=1);

namespace App\Integrations\Social\Instagram;

use App\Integrations\Social\Contracts\ErrorKind;
use App\Integrations\Social\Contracts\PlatformError;
use App\Kernel\HttpClient\HttpClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use SensitiveParameter;

/**
 * Instagram Login for publishing (ADR 0010): the authorization URL, the exchange of the code for a short-lived token and of that for a
 * long-lived one (about 60 days), and the extension of a long-lived token. Instagram wants some of these tokens in the query string; the
 * URLs of these requests are never logged and transport exceptions are never quoted.
 */
final class InstagramOAuth
{
    public const AUTHORIZE_HOST = 'www.instagram.com';
    private const TOKEN_URL = 'https://api.instagram.com/oauth/access_token';
    private const EXCHANGE_URL = 'https://graph.instagram.com/access_token';
    private const REFRESH_URL = 'https://graph.instagram.com/refresh_access_token';
    private const DEFAULT_LIFETIME = 5_184_000;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $appId,
        private readonly string $appSecret,
        private readonly string $scope,
    ) {
    }

    public function configured(): bool
    {
        return $this->appId !== '' && $this->appSecret !== '';
    }

    public function authorizationUrl(string $redirectUri, string $state): string
    {
        return 'https://' . self::AUTHORIZE_HOST . '/oauth/authorize?' . http_build_query([
            'client_id' => $this->appId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => $this->scope,
            'state' => $state,
            'force_reauth' => 'true',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Code → long-lived token.
     *
     * @throws PlatformError
     */
    public function exchangeCode(#[SensitiveParameter] string $code, string $redirectUri): InstagramTokens
    {
        // Instagram appends `#_` to the code in the redirect.
        $code = preg_replace('/#_$/', '', $code) ?? $code;
        $short = $this->request('POST', self::TOKEN_URL, ['form_params' => [
            'client_id' => $this->appId,
            'client_secret' => $this->appSecret,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ]]);
        // Newer answers wrap the object in `data`.
        $row = is_array($short['data'][0] ?? null) ? $short['data'][0] : $short;
        $shortToken = $row['access_token'] ?? null;
        $user = $row['user_id'] ?? '';
        if (!is_string($shortToken) || $shortToken === '' || !is_scalar($user) || (string) $user === '') {
            throw new PlatformError(ErrorKind::Auth, 'Instagram answered without a token.', 'Instagram не выдал доступ. Подключите аккаунт заново.');
        }
        $scope = $row['permissions'] ?? ($short['permissions'] ?? '');
        $scope = is_array($scope) ? implode(',', array_map('strval', $scope)) : (is_string($scope) ? $scope : '');

        $long = $this->request('GET', self::EXCHANGE_URL, ['query' => [
            'grant_type' => 'ig_exchange_token',
            'client_secret' => $this->appSecret,
            'access_token' => $shortToken,
        ]]);

        return $this->tokens($long, (string) $user, $scope);
    }

    /**
     * Extend a long-lived token (it must be at least a day old and not yet expired).
     *
     * @throws PlatformError `auth` when Instagram refuses (expired or revoked); `temporary` when it cannot be reached
     */
    public function refresh(#[SensitiveParameter] string $token): InstagramTokens
    {
        return $this->tokens($this->request('GET', self::REFRESH_URL, ['query' => [
            'grant_type' => 'ig_refresh_token',
            'access_token' => $token,
        ]]), '', '');
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     * @throws PlatformError
     */
    private function request(string $method, string $url, array $options): array
    {
        try {
            $response = $this->http->request($method, $url, $options + ['timeout' => 20, 'connect_timeout' => 10, 'http_errors' => false]);
        } catch (GuzzleException) {
            throw new PlatformError(ErrorKind::Temporary, 'Instagram OAuth: transport error.', 'Не удалось связаться с Instagram. Попробуйте позже.');
        }
        $status = $response->getStatusCode();
        $data = json_decode((string) $response->getBody(), true);
        if ($status >= 500 || $status === 0) {
            throw new PlatformError(ErrorKind::Temporary, sprintf('Instagram OAuth: HTTP %d.', $status), 'Instagram сейчас недоступен. Попробуйте позже.');
        }
        if (!is_array($data)) {
            throw new PlatformError(ErrorKind::Auth, sprintf('Instagram OAuth: unreadable answer (HTTP %d).', $status), 'Instagram ответил что-то непонятное. Подключите аккаунт заново.');
        }
        if ($status >= 400 || isset($data['error']) || isset($data['error_type'])) {
            $detail = is_array($data['error'] ?? null) ? ($data['error']['code'] ?? 'unknown') : ($data['error_type'] ?? 'http_' . $status);

            // The description is Instagram's, shown to nobody: it may name internals. Only a machine code is logged.
            throw new PlatformError(ErrorKind::Auth, 'Instagram OAuth refused the request: ' . (is_scalar($detail) ? (string) $detail : 'unknown') . '.', 'Доступ к Instagram закончился или отозван. Подключите аккаунт заново.');
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @throws PlatformError
     */
    private function tokens(array $data, string $userId, string $scope): InstagramTokens
    {
        $access = $data['access_token'] ?? null;
        if (!is_string($access) || $access === '') {
            throw new PlatformError(ErrorKind::Auth, 'Instagram answered without a long-lived token.', 'Instagram не выдал доступ. Подключите аккаунт заново.');
        }
        $expires = $data['expires_in'] ?? self::DEFAULT_LIFETIME;

        return new InstagramTokens($access, is_int($expires) && $expires > 0 ? $expires : self::DEFAULT_LIFETIME, $userId, $scope);
    }
}
