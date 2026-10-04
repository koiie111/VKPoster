<?php

declare(strict_types=1);

namespace App\Integrations\Social\Vk;

use App\Integrations\Social\Contracts\ErrorKind;
use App\Integrations\Social\Contracts\PlatformError;
use App\Kernel\HttpClient\HttpClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use SensitiveParameter;

/**
 * Our own thin client for the VK API (`api.vk.com/method/…`, token in the `Authorization` header so that it never sits in a URL).
 * Every failure becomes a classified `PlatformError` (see `apiError()` for the table of VK error codes). Exceptions of the transport are
 * never chained or quoted. The old `vkcom/vk-php-sdk` is not used: it is outside the dependency whitelist and we need five methods.
 *
 * Calls that publish something (`wall.post`) are `mutating`: a connection that breaks after the request left is an `unknown_outcome`.
 * File uploads and the helper calls before them are safe to repeat.
 */
final class VkApi
{
    public const API_BASE = 'https://api.vk.com/method/';

    /** Upload servers are named by VK in its answers; we only follow https links to VK's own domains. */
    private const UPLOAD_HOSTS = ['vk.com', 'vk.ru', 'userapi.com', 'vkuseraudio.net', 'vkuservideo.net', 'mycdn.me', 'vk-cdn.net'];

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly VkRateGate $gate,
        private readonly string $version = '5.199',
        private readonly string $apiBase = self::API_BASE,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     * @return array<int|string, mixed> the `response` object or list as VK sent it (a bare number or string comes back as `['value' => …]`)
     * @throws PlatformError
     */
    public function call(string $method, array $params, #[SensitiveParameter] string $token, bool $mutating = false): array
    {
        $this->gate->wait($token);
        $form = ['v' => $this->version];
        foreach ($params as $name => $value) {
            $form[$name] = is_bool($value) ? ($value ? '1' : '0') : (is_scalar($value) ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        }
        try {
            $response = $this->http->request('POST', $this->apiBase . $method, [
                'form_params' => $form,
                'headers' => ['Authorization' => 'Bearer ' . $token],
                'timeout' => 30,
                'connect_timeout' => 10,
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            throw $this->transportError($method, $e, $mutating);
        }

        $status = $response->getStatusCode();
        $decoded = json_decode((string) $response->getBody(), true);
        if (!is_array($decoded)) {
            $kind = $mutating && $status >= 500 ? ErrorKind::UnknownOutcome : ($status >= 500 || $status === 0 ? ErrorKind::Temporary : ErrorKind::Permanent);

            throw new PlatformError($kind, sprintf('VK %s: unreadable answer (HTTP %d).', $method, $status), 'ВКонтакте ответил что-то непонятное. Попробуйте позже.');
        }
        if (is_array($decoded['error'] ?? null)) {
            /** @var array<string, mixed> $error */
            $error = $decoded['error'];
            throw $this->apiError($method, $error);
        }
        $result = $decoded['response'] ?? [];

        return is_array($result) ? $result : ['value' => $result];
    }

    /**
     * Send one file to an upload server obtained from VK (`photos.getWallUploadServer`, `video.save`, …).
     *
     * @return array<string, mixed> VK's JSON answer
     * @throws PlatformError
     */
    public function upload(string $url, string $field, string $path, string $filename): array
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $allowed = false;
        foreach (self::UPLOAD_HOSTS as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
                $allowed = true;
            }
        }
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || !$allowed) {
            throw new PlatformError(ErrorKind::Permanent, 'VK returned an upload address outside its own domains.', 'ВКонтакте вернул странный адрес для загрузки файла. Попробуйте позже.');
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new PlatformError(ErrorKind::Permanent, 'The file to send cannot be read.', 'Не удалось прочитать файл для отправки.');
        }
        try {
            $response = $this->http->request('POST', $url, [
                'multipart' => [['name' => $field, 'contents' => $handle, 'filename' => $filename]],
                'timeout' => 600,
                'connect_timeout' => 10,
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            // Nothing is published yet at this step, so a failure is always safe to retry.
            throw new PlatformError(ErrorKind::Temporary, sprintf('VK upload: transport error (%s).', (new \ReflectionClass($e))->getShortName()), 'Не удалось загрузить файл во ВКонтакте. Попробуем ещё раз.');
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
        $decoded = json_decode((string) $response->getBody(), true);
        $status = $response->getStatusCode();
        if (!is_array($decoded) || $status >= 400) {
            throw new PlatformError($status >= 500 || $status === 0 || !is_array($decoded) ? ErrorKind::Temporary : ErrorKind::Permanent, sprintf('VK upload: HTTP %d.', $status), 'ВКонтакте не принял файл. Проверьте формат и размер.');
        }
        if (isset($decoded['error']) || isset($decoded['error_code'])) {
            throw new PlatformError(ErrorKind::Permanent, 'VK upload refused the file.', 'ВКонтакте не принял файл. Проверьте формат и размер.');
        }

        return $decoded;
    }

    private function transportError(string $method, GuzzleException $e, bool $mutating): PlatformError
    {
        // A connection that was never established proves nothing was sent; any other break may have happened after VK got the request.
        $kind = $mutating && !($e instanceof ConnectException) ? ErrorKind::UnknownOutcome : ErrorKind::Temporary;

        return new PlatformError($kind, sprintf('VK %s: transport error (%s).', $method, (new \ReflectionClass($e))->getShortName()), 'Не удалось связаться с ВКонтакте. Попробуйте позже.');
    }

    /**
     * VK error codes → what the pipeline does: 5, 7, 15, 17, 18, 27, 28, 203 mean the account lost access (auth); 6, 9, 29 are limits (retry later);
     * 10 and 1 are VK's own failures (temporary); 14 (captcha), 100, 214, 220+ refuse this very post (permanent).
     *
     * @param array<string, mixed> $error
     */
    private function apiError(string $method, array $error): PlatformError
    {
        $code = is_int($error['error_code'] ?? null) ? $error['error_code'] : 0;
        $text = is_string($error['error_msg'] ?? null) ? $error['error_msg'] : 'no description';
        $message = sprintf('VK %s: error %d %s', $method, $code, $text);

        return match (true) {
            $code === 5 => new PlatformError(ErrorKind::Auth, $message, 'Доступ к ВКонтакте закончился или отозван. Подключите сообщество заново.', null, $code),
            in_array($code, [7, 15, 27, 28, 203], true) => new PlatformError(ErrorKind::Auth, $message, 'У вашего аккаунта ВКонтакте нет прав публиковать в этом сообществе. Проверьте, что вы администратор или редактор, и подключите его заново.', null, $code),
            in_array($code, [17, 18], true) => new PlatformError(ErrorKind::Auth, $message, 'ВКонтакте требует подтвердить вход в аккаунт. Откройте VK, подтвердите вход и подключите сообщество заново.', null, $code),
            $code === 6 => new PlatformError(ErrorKind::RateLimited, $message, 'ВКонтакте просит не частить: слишком много запросов.', 2, $code),
            $code === 9 => new PlatformError(ErrorKind::RateLimited, $message, 'ВКонтакте временно ограничил публикации в сообществе: слишком много однотипных действий.', 60, $code),
            $code === 29 => new PlatformError(ErrorKind::RateLimited, $message, 'Достигнут лимит запросов ВКонтакте. Попробуем позже.', 600, $code),
            $code === 1 || $code === 10 || $code >= 500 => new PlatformError(ErrorKind::Temporary, $message, 'ВКонтакте сейчас недоступен. Попробуем позже.', null, $code),
            $code === 14 => new PlatformError(ErrorKind::Permanent, $message, 'ВКонтакте потребовал ввести капчу и не принял пост. Подождите немного и повторите публикацию.', null, $code),
            $code === 214 => new PlatformError(ErrorKind::Permanent, $message, 'ВКонтакте не разрешил публикацию. Возможно, исчерпан суточный лимит постов сообщества или такой пост уже есть на стене.', null, $code),
            $code === 220 || $code === 222 || $code === 224 => new PlatformError(ErrorKind::Permanent, $message, 'ВКонтакте не принял пост: в нём ссылка, которую сеть запрещает, или текст помечен как нарушение.', null, $code),
            $code === 100 => new PlatformError(ErrorKind::Permanent, $message, 'ВКонтакте не принял параметры поста. Проверьте текст и вложения.', null, $code),
            default => new PlatformError(ErrorKind::Permanent, $message, 'ВКонтакте не принял запрос. Проверьте текст и вложения.', null, $code),
        };
    }
}
