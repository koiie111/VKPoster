<?php

declare(strict_types=1);

namespace App\Integrations\Social\Instagram;

use App\Integrations\Social\Contracts\ErrorKind;
use App\Integrations\Social\Contracts\PlatformError;
use App\Kernel\HttpClient\HttpClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use SensitiveParameter;

/**
 * Our own thin client for the Instagram API with Instagram Login (`graph.instagram.com`, ADR 0010). The token travels in the
 * `Authorization` header so that it never sits in a URL. Every failure becomes a classified `PlatformError` (see `apiError()` for the
 * table of error codes); exceptions of the transport are never chained or quoted.
 *
 * Calls that publish something (`media_publish`, comments) are `mutating`: a connection that breaks after the request left is an
 * `unknown_outcome`. Creating a container and every read are safe to repeat.
 */
final class InstagramApi
{
    public const GRAPH_BASE = 'https://graph.instagram.com';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $version = 'v23.0',
        private readonly string $base = self::GRAPH_BASE,
    ) {
    }

    /**
     * @param array<string, scalar> $query
     * @return array<int|string, mixed> the decoded JSON object
     * @throws PlatformError
     */
    public function get(string $path, array $query, #[SensitiveParameter] string $token): array
    {
        return $this->send('GET', $path, ['query' => $query], $token, false);
    }

    /**
     * @param array<string, scalar> $params
     * @return array<int|string, mixed>
     * @throws PlatformError
     */
    public function post(string $path, array $params, #[SensitiveParameter] string $token, bool $mutating = false): array
    {
        $form = [];
        foreach ($params as $name => $value) {
            $form[$name] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        return $this->send('POST', $path, ['form_params' => $form], $token, $mutating);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<int|string, mixed>
     * @throws PlatformError
     */
    private function send(string $method, string $path, array $options, #[SensitiveParameter] string $token, bool $mutating): array
    {
        $options += ['headers' => ['Authorization' => 'Bearer ' . $token], 'timeout' => 30, 'connect_timeout' => 10, 'http_errors' => false];
        $label = $method . ' ' . $path;
        try {
            $response = $this->http->request($method, $this->base . '/' . $this->version . '/' . ltrim($path, '/'), $options);
        } catch (GuzzleException $e) {
            // A connection that was never established proves nothing was sent; any other break may have happened after Instagram got the request.
            $kind = $mutating && !($e instanceof ConnectException) ? ErrorKind::UnknownOutcome : ErrorKind::Temporary;

            throw new PlatformError($kind, sprintf('Instagram %s: transport error (%s).', $label, (new \ReflectionClass($e))->getShortName()), 'Не удалось связаться с Instagram. Попробуйте позже.');
        }

        $status = $response->getStatusCode();
        $decoded = json_decode((string) $response->getBody(), true);
        if (!is_array($decoded)) {
            $kind = $mutating && $status >= 500 ? ErrorKind::UnknownOutcome : ($status >= 500 || $status === 0 ? ErrorKind::Temporary : ErrorKind::Permanent);

            throw new PlatformError($kind, sprintf('Instagram %s: unreadable answer (HTTP %d).', $label, $status), 'Instagram ответил что-то непонятное. Попробуйте позже.');
        }
        if (is_array($decoded['error'] ?? null)) {
            /** @var array<string, mixed> $error */
            $error = $decoded['error'];
            throw $this->apiError($label, $status, $error, $mutating);
        }
        if ($status >= 400) {
            throw new PlatformError($status >= 500 ? ($mutating ? ErrorKind::UnknownOutcome : ErrorKind::Temporary) : ErrorKind::Permanent, sprintf('Instagram %s: HTTP %d.', $label, $status), 'Instagram не принял запрос. Проверьте пост и попробуйте позже.');
        }

        return $decoded;
    }

    /**
     * Error codes → what the pipeline does: 190 (token invalid or expired), 102, 10 and 200–299 (rights) mean the account lost access (auth);
     * 4, 17, 32, 613, 80002 and subcode 2207042 (daily publishing limit) are limits (retry later); 1, 2 and HTTP 5xx are Instagram's own
     * failures (temporary); 9007 and subcode 2207027 mean "the container is not ready yet" (temporary); the rest refuses this very post (permanent).
     *
     * @param array<string, mixed> $error
     */
    private function apiError(string $label, int $status, array $error, bool $mutating): PlatformError
    {
        $code = is_int($error['code'] ?? null) ? $error['code'] : 0;
        $sub = is_int($error['error_subcode'] ?? null) ? $error['error_subcode'] : 0;
        $message = sprintf('Instagram %s: error %d/%d %s', $label, $code, $sub, is_string($error['type'] ?? null) ? $error['type'] : 'unknown');

        return match (true) {
            $code === 190 || $code === 102 => new PlatformError(ErrorKind::Auth, $message, 'Доступ к Instagram закончился или отозван. Подключите аккаунт заново.', null, $code),
            $code === 10 || ($code >= 200 && $code <= 299) => new PlatformError(ErrorKind::Auth, $message, 'У приложения нет прав публиковать в этот аккаунт Instagram. Подключите аккаунт заново и разрешите все права.', null, $code),
            $sub === 2207042 => new PlatformError(ErrorKind::RateLimited, $message, 'Достигнут суточный лимит публикаций Instagram для этого аккаунта. Пост уйдёт позже.', 3600, $code),
            in_array($code, [4, 17, 32, 613, 80002], true) => new PlatformError(ErrorKind::RateLimited, $message, 'Instagram просит не частить: слишком много запросов. Попробуем позже.', 600, $code),
            $code === 9007 || $sub === 2207027 => new PlatformError(ErrorKind::Temporary, $message, 'Instagram ещё обрабатывает файл. Попробуем ещё раз.', null, $code),
            $code === 1 || $code === 2 || $status >= 500 => new PlatformError($mutating && $status >= 500 ? ErrorKind::UnknownOutcome : ErrorKind::Temporary, $message, 'Instagram сейчас недоступен. Попробуем позже.', null, $code),
            $code === 368 => new PlatformError(ErrorKind::Permanent, $message, 'Instagram временно ограничил публикации в этом аккаунте. Проверьте приложение Instagram.', null, $code),
            in_array($sub, [2207001, 2207003, 2207004, 2207005, 2207006, 2207008, 2207009, 2207010, 2207020, 2207023, 2207024, 2207026, 2207028, 2207032, 2207035, 2207036, 2207037, 2207038, 2207050], true) => new PlatformError(ErrorKind::Permanent, $message, 'Instagram не принял файл: проверьте формат, размер и соотношение сторон. Для Reels нужно вертикальное видео.', null, $code),
            default => new PlatformError(ErrorKind::Permanent, $message, 'Instagram не принял запрос. Проверьте текст и файлы.', null, $code),
        };
    }
}
