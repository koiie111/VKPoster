<?php

declare(strict_types=1);

namespace App\Integrations\Social\YouTube;

use App\Integrations\Social\Contracts\ErrorKind;
use App\Integrations\Social\Contracts\PlatformError;
use App\Kernel\HttpClient\HttpClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use SensitiveParameter;

/** Thin YouTube Data API client with resumable upload response and URL validation. */
final class YouTubeApi
{
    private const API = 'https://www.googleapis.com/youtube/v3/';
    private const UPLOAD = 'https://www.googleapis.com/upload/youtube/v3/';

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    /** @return array<string, mixed> */
    public function channels(#[SensitiveParameter] string $token): array
    {
        return $this->json('GET', self::API . 'channels?part=snippet,contentDetails,statistics&mine=true', $token);
    }

    /** @return array<string, mixed> */
    public function video(string $id, #[SensitiveParameter] string $token): array
    {
        return $this->json('GET', self::API . 'videos?part=status,processingDetails,snippet,statistics&id=' . rawurlencode($id), $token);
    }

    /**
     * @param list<string> $ids
     * @return array<string, mixed>
     */
    public function videos(array $ids, #[SensitiveParameter] string $token): array
    {
        return $this->json('GET', self::API . 'videos?part=snippet&id=' . rawurlencode(implode(',', $ids)), $token);
    }

    /** @return array<string, mixed> */
    public function playlistItems(string $playlistId, #[SensitiveParameter] string $token): array
    {
        return $this->json('GET', self::API . 'playlistItems?part=contentDetails&maxResults=50&playlistId=' . rawurlencode($playlistId), $token);
    }

    /** @param array<string, mixed> $metadata */
    public function startUpload(array $metadata, int $size, #[SensitiveParameter] string $token): string
    {
        $response = $this->send('POST', self::UPLOAD . 'videos?uploadType=resumable&part=snippet,status', $token, [
            'json' => $metadata,
            'headers' => [
                'Content-Type' => 'application/json; charset=UTF-8',
                'X-Upload-Content-Length' => (string) $size,
                'X-Upload-Content-Type' => 'video/mp4',
            ],
        ], true);
        $this->check($response, true);
        $url = $response->getHeaderLine('Location');
        $this->validateUploadUrl($url);
        return $url;
    }

    /** @return array{id: ?string, offset: int} */
    public function uploadStatus(string $url, int $total, #[SensitiveParameter] string $token): array
    {
        $this->validateUploadUrl($url);
        $response = $this->send('PUT', $url, $token, ['headers' => ['Content-Range' => 'bytes */' . $total, 'Content-Length' => '0'], 'body' => '']);
        return $this->uploadResponse($response, $total);
    }

    /** @return array{id: ?string, offset: int} */
    public function uploadChunk(string $url, string $bytes, int $offset, int $total, #[SensitiveParameter] string $token): array
    {
        $this->validateUploadUrl($url);
        $size = strlen($bytes);
        if ($size < 1 || $offset < 0 || $offset + $size > $total) {
            throw new \LogicException('Invalid YouTube upload chunk.');
        }
        $response = $this->send('PUT', $url, $token, ['headers' => [
            'Content-Type' => 'video/mp4',
            'Content-Length' => (string) $size,
            'Content-Range' => sprintf('bytes %d-%d/%d', $offset, $offset + $size - 1, $total),
        ], 'body' => $bytes, 'timeout' => 120]);
        return $this->uploadResponse($response, $total);
    }

    public function deleteVideo(string $id, #[SensitiveParameter] string $token): void
    {
        $response = $this->send('DELETE', self::API . 'videos?id=' . rawurlencode($id), $token, [], true);
        $this->check($response, true);
    }

    /** @param array<string, mixed> $snippet */
    public function updateVideo(string $id, array $snippet, #[SensitiveParameter] string $token): void
    {
        $response = $this->send('PUT', self::API . 'videos?part=snippet', $token, ['json' => ['id' => $id, 'snippet' => $snippet]], true);
        $this->check($response, true);
    }

    public function setThumbnail(string $id, string $path, #[SensitiveParameter] string $token): void
    {
        $bytes = file_get_contents($path);
        if ($bytes === false || strlen($bytes) > 2 * 1024 * 1024) {
            throw new PlatformError(ErrorKind::Permanent, 'YouTube thumbnail is invalid.', 'Обложка YouTube должна быть JPEG до 2 МБ.');
        }
        $response = $this->send(
            'POST',
            self::UPLOAD . 'thumbnails/set?videoId=' . rawurlencode($id) . '&uploadType=media',
            $token,
            ['headers' => ['Content-Type' => 'image/jpeg', 'Content-Length' => (string) strlen($bytes)], 'body' => $bytes, 'timeout' => 60],
            true
        );
        $this->check($response, true);
    }

    /** @return array<string, mixed> */
    private function json(string $method, string $url, #[SensitiveParameter] string $token): array
    {
        $response = $this->send($method, $url, $token, []);
        $this->check($response, false);
        $data = json_decode((string) $response->getBody(), true);
        if (!is_array($data)) {
            throw new PlatformError(ErrorKind::Temporary, 'YouTube returned invalid JSON.', 'YouTube ответил неполно. Повторим позже.');
        }
        return $data;
    }

    /** @return array{id: ?string, offset: int} */
    private function uploadResponse(ResponseInterface $response, int $total): array
    {
        if ($response->getStatusCode() === 308) {
            $range = $response->getHeaderLine('Range');
            if ($range === '') {
                return ['id' => null, 'offset' => 0];
            }
            if (preg_match('/^bytes=0-(\d+)$/D', $range, $match) !== 1 || (int) $match[1] >= $total) {
                throw new PlatformError(ErrorKind::UnknownOutcome, 'YouTube upload range is invalid.', 'Не удалось определить загруженную часть видео. Проверьте YouTube.');
            }
            return ['id' => null, 'offset' => (int) $match[1] + 1];
        }
        if (in_array($response->getStatusCode(), [200, 201], true)) {
            $data = json_decode((string) $response->getBody(), true);
            $id = is_array($data) && is_string($data['id'] ?? null) ? $data['id'] : null;
            if ($id === null || $id === '') {
                throw new PlatformError(ErrorKind::UnknownOutcome, 'YouTube upload completed without a video id.', 'Видео могло появиться на YouTube. Проверьте канал.');
            }
            return ['id' => $id, 'offset' => $total];
        }
        $this->check($response, false);
        throw new PlatformError(ErrorKind::UnknownOutcome, 'YouTube upload returned unexpected status.', 'Проверьте результат загрузки на YouTube.');
    }

    /** @param array<string, mixed> $options */
    private function send(string $method, string $url, #[SensitiveParameter] string $token, array $options, bool $mutating = false): ResponseInterface
    {
        $options['headers'] = ($options['headers'] ?? []) + ['Authorization' => 'Bearer ' . $token];
        $options += ['connect_timeout' => 10, 'timeout' => 30, 'http_errors' => false, 'allow_redirects' => false];
        try {
            return $this->http->request($method, $url, $options);
        } catch (GuzzleException) {
            // A saved resumable URI can always be queried for the server-confirmed offset before retrying.
            throw new PlatformError($mutating ? ErrorKind::UnknownOutcome : ErrorKind::Temporary, 'YouTube transport error.', $mutating ? 'YouTube мог принять запрос. Проверьте канал перед повторной отправкой.' : 'Не удалось связаться с YouTube. Повторим загрузку позже.');
        }
    }

    private function check(ResponseInterface $response, bool $mutating): void
    {
        $status = $response->getStatusCode();
        if ($status < 400) {
            return;
        }
        $data = json_decode((string) $response->getBody(), true);
        $error = is_array($data) && is_array($data['error'] ?? null) ? $data['error'] : [];
        $reasons = is_array($error['errors'] ?? null) ? $error['errors'] : [];
        $reason = is_array($reasons[0] ?? null) && is_string($reasons[0]['reason'] ?? null) ? $reasons[0]['reason'] : 'http_' . $status;
        $kind = match (true) {
            $status === 401 || $reason === 'authError' => ErrorKind::Auth,
            $status === 403 && in_array($reason, ['quotaExceeded', 'dailyLimitExceeded', 'uploadLimitExceeded', 'rateLimitExceeded'], true) => ErrorKind::RateLimited,
            $status === 429 => ErrorKind::RateLimited,
            $status >= 500 => $mutating ? ErrorKind::UnknownOutcome : ErrorKind::Temporary,
            $status === 404 => ErrorKind::UnknownOutcome,
            default => ErrorKind::Permanent,
        };
        $retryAfter = in_array($reason, ['quotaExceeded', 'dailyLimitExceeded', 'uploadLimitExceeded'], true) ? 86400 : ($kind === ErrorKind::RateLimited ? 3600 : null);
        throw new PlatformError($kind, 'YouTube API: ' . $reason, $kind === ErrorKind::RateLimited ? 'Квота YouTube исчерпана. Публикация будет отложена.' : 'YouTube не принял запрос. Проверьте видео и доступ к каналу.', $retryAfter);
    }

    private function validateUploadUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || !in_array(strtolower($parts['host'] ?? ''), ['www.googleapis.com', 'upload.youtube.com'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || !str_starts_with($parts['path'] ?? '', '/upload/youtube/v3/videos')) {
            throw new PlatformError(ErrorKind::UnknownOutcome, 'YouTube returned an unsafe upload URL.', 'Не удалось продолжить загрузку видео на YouTube.');
        }
    }
}
