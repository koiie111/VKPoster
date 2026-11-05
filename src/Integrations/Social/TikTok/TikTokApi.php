<?php

declare(strict_types=1);

namespace App\Integrations\Social\TikTok;

use App\Integrations\Social\Contracts\ErrorKind;
use App\Integrations\Social\Contracts\PlatformError;
use App\Kernel\HttpClient\HttpClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use SensitiveParameter;

/** TikTok Content Posting API. Tokens are sent only in headers; response log ids are never treated as user text. */
final class TikTokApi
{
    private const BASE = 'https://open.tiktokapis.com/v2/post/publish/';

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    /** @return array<string, mixed> */
    public function creator(#[SensitiveParameter] string $token): array
    {
        return $this->post('creator_info/query/', [], $token, false);
    }

    /**
     * @param array<string, mixed> $postInfo
     * @return array<string, mixed>
     */
    public function startPull(array $postInfo, string $url, #[SensitiveParameter] string $token): array
    {
        return $this->post('video/init/', ['post_info' => $postInfo, 'source_info' => ['source' => 'PULL_FROM_URL', 'video_url' => $url]], $token, true);
    }

    /** @return array<string, mixed> */
    public function status(string $publishId, #[SensitiveParameter] string $token): array
    {
        return $this->post('status/fetch/', ['publish_id' => $publishId], $token, false);
    }

    /** @return array<string, mixed> */
    public function videoStats(string $videoId, #[SensitiveParameter] string $token): array
    {
        $url = 'https://open.tiktokapis.com/v2/video/query/?fields=id,view_count,like_count,comment_count,share_count';
        try {
            $response = $this->http->request('POST', $url, [
                'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
                'json' => ['filters' => ['video_ids' => [$videoId]]], 'timeout' => 20, 'connect_timeout' => 10, 'http_errors' => false,
            ]);
        } catch (GuzzleException) {
            throw new PlatformError(ErrorKind::Temporary, 'TikTok statistics transport error.', 'TikTok сейчас не отдаёт статистику.');
        }
        $body = json_decode((string) $response->getBody(), true);
        $code = is_array($body) && is_array($body['error'] ?? null) && is_string($body['error']['code'] ?? null) ? $body['error']['code'] : '';
        if ($response->getStatusCode() === 401 || in_array($code, ['access_token_invalid', 'scope_not_authorized'], true)) {
            throw new PlatformError(ErrorKind::Auth, 'TikTok statistics permission denied.', 'Нет доступа к статистике TikTok.');
        }
        if ($response->getStatusCode() >= 400 || ($code !== '' && $code !== 'ok') || !is_array($body) || !is_array($body['data'] ?? null)) {
            throw new PlatformError(ErrorKind::Temporary, 'TikTok statistics failed.', 'TikTok сейчас не отдаёт статистику.');
        }
        return $body['data'];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function post(string $path, array $body, #[SensitiveParameter] string $token, bool $mutating): array
    {
        try {
            $response = $this->http->request('POST', self::BASE . $path, [
                'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json; charset=UTF-8'],
                'json' => $body, 'timeout' => 30, 'connect_timeout' => 10, 'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            $kind = $mutating && !($e instanceof ConnectException) ? ErrorKind::UnknownOutcome : ErrorKind::Temporary;
            throw new PlatformError($kind, 'TikTok ' . $path . ' transport error.', 'TikTok не ответил. Проверьте канал перед повторной публикацией.');
        }
        $status = $response->getStatusCode();
        $body = json_decode((string) $response->getBody(), true);
        if (!is_array($body)) {
            throw new PlatformError($mutating ? ErrorKind::UnknownOutcome : ErrorKind::Temporary, 'TikTok ' . $path . ' invalid response HTTP ' . $status, 'TikTok ответил неполно. Проверьте канал.');
        }
        $error = is_array($body['error'] ?? null) ? $body['error'] : [];
        $code = is_string($error['code'] ?? null) ? $error['code'] : '';
        if ($status >= 400 || ($code !== '' && $code !== 'ok')) {
            $kind = match (true) {
                $status === 401 || in_array($code, ['access_token_invalid', 'scope_not_authorized', 'invalid_grant'], true) => ErrorKind::Auth,
                $status === 429 || in_array($code, ['rate_limit_exceeded', 'spam_risk_too_many_posts', 'reached_active_user_cap'], true) => ErrorKind::RateLimited,
                $status >= 500 || $code === 'internal_error' => $mutating ? ErrorKind::UnknownOutcome : ErrorKind::Temporary,
                default => ErrorKind::Permanent,
            };
            $retryAfter = $code === 'spam_risk_too_many_posts' || $code === 'reached_active_user_cap' ? 86400 : ($kind === ErrorKind::RateLimited ? 3600 : null);
            throw new PlatformError($kind, 'TikTok ' . $path . ': ' . ($code === '' ? 'HTTP ' . $status : $code), $kind === ErrorKind::RateLimited ? 'Суточный лимит TikTok исчерпан. Публикация будет отложена.' : 'TikTok не принял запрос. Проверьте настройки видео и доступ аккаунта.', $retryAfter);
        }
        $data = $body['data'] ?? null;
        if (!is_array($data)) {
            throw new PlatformError($mutating ? ErrorKind::UnknownOutcome : ErrorKind::Temporary, 'TikTok ' . $path . ' missing data.', 'TikTok ответил неполно. Проверьте канал.');
        }
        return $data;
    }
}
