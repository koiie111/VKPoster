<?php

declare(strict_types=1);

namespace App\Integrations\Social\YouTube;

use App\Integrations\Social\Contracts\ErrorKind;
use App\Integrations\Social\Contracts\PlatformError;
use App\Kernel\HttpClient\HttpClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use SensitiveParameter;

/** Dedicated Google OAuth application for YouTube publishing; it never reuses social sign-in credentials. */
final class YouTubeOAuth
{
    public const AUTHORIZE_HOST = 'accounts.google.com';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const SCOPES = 'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube.readonly https://www.googleapis.com/auth/youtube.force-ssl';

    public function __construct(private readonly HttpClientInterface $http, private readonly string $clientId, private readonly string $clientSecret)
    {
    }

    public function configured(): bool
    {
        return $this->clientId !== '' && $this->clientSecret !== '';
    }

    public function authorizationUrl(string $redirectUri, string $state, string $challenge): string
    {
        return 'https://' . self::AUTHORIZE_HOST . '/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => $this->clientId, 'redirect_uri' => $redirectUri, 'response_type' => 'code',
            'access_type' => 'offline', 'prompt' => 'consent', 'scope' => self::SCOPES,
            'state' => $state, 'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchange(#[SensitiveParameter] string $code, string $redirectUri, #[SensitiveParameter] string $verifier): YouTubeTokens
    {
        return $this->tokens($this->request([
            'client_id' => $this->clientId, 'client_secret' => $this->clientSecret,
            'grant_type' => 'authorization_code', 'code' => $code,
            'redirect_uri' => $redirectUri, 'code_verifier' => $verifier,
        ]), '');
    }

    public function refresh(#[SensitiveParameter] string $refreshToken): YouTubeTokens
    {
        return $this->tokens($this->request([
            'client_id' => $this->clientId, 'client_secret' => $this->clientSecret,
            'grant_type' => 'refresh_token', 'refresh_token' => $refreshToken,
        ], true), $refreshToken);
    }

    /**
     * @param array<string, string> $fields
     * @return array<string, mixed>
     */
    private function request(#[SensitiveParameter] array $fields, bool $refresh = false): array
    {
        try {
            $response = $this->http->request('POST', self::TOKEN_URL, ['form_params' => $fields, 'timeout' => 20, 'connect_timeout' => 10, 'http_errors' => false]);
        } catch (GuzzleException $error) {
            throw new PlatformError(
                $refresh && !($error instanceof ConnectException) ? ErrorKind::UnknownOutcome : ErrorKind::Temporary,
                'Google OAuth transport error.',
                'Не удалось обновить доступ YouTube.'
            );
        }
        $status = $response->getStatusCode();
        $body = json_decode((string) $response->getBody(), true);
        if (!is_array($body)) {
            throw new PlatformError($refresh ? ErrorKind::UnknownOutcome : ErrorKind::Temporary, 'Google OAuth invalid response.', 'Google ответил неполно.');
        }
        if ($status >= 400 || isset($body['error'])) {
            $code = is_string($body['error'] ?? null) ? $body['error'] : 'http_' . $status;
            throw new PlatformError(
                $status >= 500 ? ErrorKind::Temporary : ErrorKind::Auth,
                'Google OAuth: ' . $code,
                'Доступ к YouTube закончился или отозван. Подключите канал заново.'
            );
        }
        return $body;
    }

    /** @param array<string, mixed> $data */
    private function tokens(array $data, string $existingRefresh): YouTubeTokens
    {
        $access = $data['access_token'] ?? null;
        $refresh = is_string($data['refresh_token'] ?? null) ? $data['refresh_token'] : $existingRefresh;
        $expires = $data['expires_in'] ?? null;
        if (!is_string($access) || $access === '' || $refresh === '' || !is_int($expires) || $expires < 1) {
            throw new PlatformError(ErrorKind::Auth, 'Google OAuth response missing offline tokens.', 'Google не выдал постоянный доступ к YouTube. Подключите канал заново и разрешите доступ.');
        }
        return new YouTubeTokens($access, $refresh, $expires, is_string($data['scope'] ?? null) ? $data['scope'] : '');
    }
}
