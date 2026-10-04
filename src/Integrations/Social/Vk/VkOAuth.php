<?php

declare(strict_types=1);

namespace App\Integrations\Social\Vk;

use App\Integrations\OAuth\Pkce;
use App\Integrations\Social\Contracts\ErrorKind;
use App\Integrations\Social\Contracts\PlatformError;
use App\Kernel\HttpClient\HttpClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use SensitiveParameter;

/**
 * VK ID OAuth 2.1 for publishing (the rights to the walls of communities, not just sign-in): authorization URL with PKCE, exchange of the
 * code, and refresh of the token pair. The access token lives 60 minutes, the refresh token 180 days and works once (ADR 0006).
 */
final class VkOAuth
{
    public const AUTHORIZE_HOST = 'id.vk.com';
    private const TOKEN_URL = 'https://id.vk.com/oauth2/auth';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $scope,
    ) {
    }

    public function configured(): bool
    {
        return $this->clientId !== '';
    }

    public function authorizationUrl(string $redirectUri, string $state, Pkce $pkce): string
    {
        return 'https://' . self::AUTHORIZE_HOST . '/authorize?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'code_challenge' => $pkce->challenge,
            'code_challenge_method' => 'S256',
            'scope' => $this->scope,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @return array{tokens: VkTokens, deviceId: string}
     * @throws PlatformError
     */
    public function exchangeCode(#[SensitiveParameter] string $code, string $verifier, string $redirectUri, string $deviceId, string $state): array
    {
        $data = $this->post([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $verifier,
            'redirect_uri' => $redirectUri,
            'client_id' => $this->clientId,
            'device_id' => $deviceId,
            'state' => $state,
        ]);

        return ['tokens' => $this->tokens($data), 'deviceId' => $deviceId];
    }

    /**
     * @throws PlatformError `auth` when VK refuses the refresh token (expired, already used, revoked); `temporary` when VK cannot be reached
     */
    public function refresh(#[SensitiveParameter] string $refreshToken, string $deviceId): VkTokens
    {
        return $this->tokens($this->post([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $this->clientId,
            'device_id' => $deviceId,
            'state' => Pkce::base64Url(random_bytes(24)),
        ]));
    }

    /**
     * @param array<string, string> $form
     * @return array<string, mixed>
     * @throws PlatformError
     */
    private function post(array $form): array
    {
        if ($this->clientSecret !== '') {
            $form['client_secret'] = $this->clientSecret;
        }
        try {
            $response = $this->http->request('POST', self::TOKEN_URL, ['form_params' => $form, 'timeout' => 20, 'connect_timeout' => 10, 'http_errors' => false]);
        } catch (GuzzleException) {
            throw new PlatformError(ErrorKind::Temporary, 'VK ID: transport error.', 'Не удалось связаться с ВКонтакте. Попробуйте позже.');
        }
        $status = $response->getStatusCode();
        $data = json_decode((string) $response->getBody(), true);
        if (!is_array($data)) {
            throw new PlatformError($status >= 500 || $status === 0 ? ErrorKind::Temporary : ErrorKind::Auth, sprintf('VK ID: unreadable answer (HTTP %d).', $status), 'ВКонтакте ответил что-то непонятное. Попробуйте позже.');
        }
        if ($status >= 500) {
            throw new PlatformError(ErrorKind::Temporary, sprintf('VK ID: HTTP %d.', $status), 'ВКонтакте сейчас недоступен. Попробуйте позже.');
        }
        if (is_string($data['error'] ?? null) || $status >= 400) {
            $error = is_string($data['error'] ?? null) ? $data['error'] : 'http_' . $status;

            // The description is VK's, shown to nobody: it may name internals. Only the machine code is logged.
            throw new PlatformError(ErrorKind::Auth, 'VK ID refused the request: ' . $error . '.', 'Доступ к ВКонтакте закончился или отозван. Подключите сообщество заново.');
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @throws PlatformError
     */
    private function tokens(array $data): VkTokens
    {
        $access = $data['access_token'] ?? null;
        $refresh = $data['refresh_token'] ?? null;
        if (!is_string($access) || $access === '' || !is_string($refresh) || $refresh === '') {
            throw new PlatformError(ErrorKind::Auth, 'VK ID answered without a token pair.', 'ВКонтакте не выдал доступ. Подключите сообщество заново.');
        }
        $expires = $data['expires_in'] ?? 3600;
        $user = $data['user_id'] ?? '';

        return new VkTokens($access, $refresh, is_int($expires) && $expires > 0 ? $expires : 3600, is_scalar($user) ? (string) $user : '', is_string($data['scope'] ?? null) ? $data['scope'] : '');
    }
}
