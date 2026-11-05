<?php

declare(strict_types=1);

namespace App\Integrations\Social\TikTok;

use App\Integrations\Social\Contracts\ErrorKind;
use App\Integrations\Social\Contracts\PlatformError;
use App\Kernel\HttpClient\HttpClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use SensitiveParameter;

/** TikTok Login Kit web OAuth. The state is verified by the connection controller. */
final class TikTokOAuth
{
    public const AUTHORIZE_HOST = 'www.tiktok.com';
    private const TOKEN_URL = 'https://open.tiktokapis.com/v2/oauth/token/';

    public function __construct(private readonly HttpClientInterface $http, private readonly string $clientKey, private readonly string $clientSecret)
    {
    }

    public function configured(): bool
    {
        return $this->clientKey !== '' && $this->clientSecret !== '';
    }

    public function authorizationUrl(string $redirectUri, string $state): string
    {
        return 'https://' . self::AUTHORIZE_HOST . '/v2/auth/authorize/?' . http_build_query([
            'client_key' => $this->clientKey,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'user.info.basic,video.publish,video.list',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchange(#[SensitiveParameter] string $code, string $redirectUri): TikTokTokens
    {
        return $this->tokens($this->request([
            'client_key' => $this->clientKey,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ]));
    }

    public function refresh(#[SensitiveParameter] string $refreshToken): TikTokTokens
    {
        return $this->tokens($this->request([
            'client_key' => $this->clientKey,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ], true));
    }

    /**
     * @param array<string, string> $fields
     * @return array<string, mixed>
     */
    private function request(#[SensitiveParameter] array $fields, bool $rotating = false): array
    {
        try {
            $response = $this->http->request('POST', self::TOKEN_URL, ['form_params' => $fields, 'timeout' => 20, 'connect_timeout' => 10, 'http_errors' => false]);
        } catch (GuzzleException $e) {
            $kind = $rotating && !($e instanceof ConnectException) ? ErrorKind::UnknownOutcome : ErrorKind::Temporary;
            throw new PlatformError($kind, 'TikTok OAuth transport error.', 'Не удалось обновить доступ TikTok.');
        }
        $status = $response->getStatusCode();
        $data = json_decode((string) $response->getBody(), true);
        if (!is_array($data)) {
            throw new PlatformError($rotating ? ErrorKind::UnknownOutcome : ErrorKind::Temporary, 'TikTok OAuth invalid response.', 'TikTok ответил неполно.');
        }
        if ($status >= 400 || isset($data['error'])) {
            $code = is_string($data['error'] ?? null) ? $data['error'] : 'http_' . $status;
            $kind = $status >= 500 ? ErrorKind::Temporary : ErrorKind::Auth;
            throw new PlatformError($kind, 'TikTok OAuth: ' . $code, 'Доступ к TikTok закончился или отозван. Подключите аккаунт заново.');
        }
        return $data;
    }

    /** @param array<string, mixed> $data */
    private function tokens(array $data): TikTokTokens
    {
        $access = $data['access_token'] ?? null;
        $refresh = $data['refresh_token'] ?? null;
        $openId = $data['open_id'] ?? null;
        $expires = $data['expires_in'] ?? null;
        if (!is_string($access) || $access === '' || !is_string($refresh) || $refresh === '' || !is_string($openId) || $openId === '' || !is_int($expires) || $expires < 1) {
            throw new PlatformError(ErrorKind::Auth, 'TikTok OAuth response missing tokens.', 'TikTok не выдал полный доступ. Подключите аккаунт заново.');
        }
        $scope = is_string($data['scope'] ?? null) ? $data['scope'] : '';
        if (!str_contains($scope, 'video.publish')) {
            throw new PlatformError(ErrorKind::Auth, 'TikTok video.publish scope missing.', 'Разрешите публикацию видео при подключении TikTok.');
        }
        return new TikTokTokens($access, $refresh, $expires, $openId, $scope);
    }
}
