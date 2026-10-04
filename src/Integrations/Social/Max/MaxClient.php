<?php

declare(strict_types=1);

namespace App\Integrations\Social\Max;

use App\Integrations\Social\Contracts\ErrorKind;
use App\Integrations\Social\Contracts\PlatformError;
use App\Kernel\HttpClient\HttpClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use SensitiveParameter;

/**
 * Our own thin client for the MAX bot API (one instance per bot token). The token travels only in the `Authorization` header,
 * never in a URL, and exceptions from the transport are never chained or quoted, so nothing that reaches a log or a page can
 * contain it. Every failure becomes a classified `PlatformError`.
 *
 * Calls that publish something treat a connection that breaks after the request left as `unknown_outcome`: MAX may already have
 * the post, and a retry would publish it twice. A rejected request (HTTP 400) is different: nothing was posted.
 */
final class MaxClient
{
    public const API_BASE = 'https://platform-api2.max.ru';

    /** Error code MAX gives while an uploaded file is still being processed. */
    public const NOT_READY = 'attachment.not.ready';

    public function __construct(
        private readonly HttpClientInterface $http,
        #[SensitiveParameter]
        private readonly string $token,
        private readonly string $apiBase = self::API_BASE,
    ) {
    }

    /**
     * The bot itself (user_id, name, username).
     *
     * @return array<string, mixed>
     * @throws PlatformError
     */
    public function getMe(): array
    {
        return $this->call('GET', '/me');
    }

    /**
     * @return array<string, mixed>
     * @throws PlatformError
     */
    public function getChat(int $chatId): array
    {
        return $this->call('GET', '/chats/' . $chatId);
    }

    /**
     * A public channel or group by its link name (`mychannel` of `max.ru/mychannel`).
     *
     * @return array<string, mixed>
     * @throws PlatformError
     */
    public function getChatByLink(string $link): array
    {
        return $this->call('GET', '/chats/' . rawurlencode($link));
    }

    /**
     * What the bot is in this chat: admin flags and the list of its permissions.
     *
     * @return array<string, mixed>
     * @throws PlatformError
     */
    public function getMembership(int $chatId): array
    {
        return $this->call('GET', '/chats/' . $chatId . '/members/me');
    }

    /**
     * Members of a chat among the given users (used to ask "is this person an administrator").
     *
     * @param list<int> $userIds
     * @return list<array<string, mixed>>
     * @throws PlatformError
     */
    public function getMembers(int $chatId, array $userIds): array
    {
        $result = $this->call('GET', '/chats/' . $chatId . '/members', ['user_ids' => implode(',', $userIds)]);

        return $this->items($result['members'] ?? []);
    }

    /**
     * @param array<string, mixed> $body text, format, attachments, notify
     * @param bool $disablePreview do not build a preview card for links
     * @return array<string, mixed> the sent message (`body.mid`, `url`, …)
     * @throws PlatformError
     */
    public function sendMessage(int $chatId, array $body, bool $disablePreview = false): array
    {
        $query = ['chat_id' => $chatId] + ($disablePreview ? ['disable_link_preview' => 'true'] : []);
        $result = $this->call('POST', '/messages', $query, $body, true);
        $message = $result['message'] ?? null;

        return is_array($message) ? $message : [];
    }

    /**
     * A message into a person's own chat with the bot (answers to `/start`).
     *
     * @throws PlatformError
     */
    public function sendDirect(int $userId, string $text): void
    {
        $this->call('POST', '/messages', ['user_id' => $userId], ['text' => $text], true);
    }

    /**
     * @param array<string, mixed> $body text, format, optionally attachments (omitted = unchanged)
     * @throws PlatformError
     */
    public function editMessage(string $messageId, array $body): void
    {
        $this->expectSuccess($this->call('PUT', '/messages', ['message_id' => $messageId], $body, true));
    }

    /**
     * @throws PlatformError
     */
    public function deleteMessage(string $messageId): void
    {
        $this->expectSuccess($this->call('DELETE', '/messages', ['message_id' => $messageId], null, true));
    }

    /**
     * @throws PlatformError
     */
    public function pinMessage(int $chatId, string $messageId, bool $notify = false): void
    {
        $this->expectSuccess($this->call('PUT', '/chats/' . $chatId . '/pin', [], ['message_id' => $messageId, 'notify' => $notify], true));
    }

    /**
     * @throws PlatformError
     */
    public function unpinMessage(int $chatId): void
    {
        $this->expectSuccess($this->call('DELETE', '/chats/' . $chatId . '/pin', [], null, true));
    }

    /**
     * Step one of an upload: where to send the file (and, for video and files, the token to attach it by).
     *
     * @param string $type image | video | file
     * @return array{url: string, token: ?string}
     * @throws PlatformError
     */
    public function uploadSlot(string $type): array
    {
        $result = $this->call('POST', '/uploads', ['type' => $type]);
        $url = $result['url'] ?? null;
        if (!is_string($url) || preg_match('~^https://~i', $url) !== 1) {
            throw new PlatformError(ErrorKind::Temporary, 'MAX returned no usable upload address.', 'MAX не дал адрес для загрузки файла. Попробуйте позже.');
        }

        return ['url' => $url, 'token' => is_string($result['token'] ?? null) ? $result['token'] : null];
    }

    /**
     * Step two: send the file bytes to the upload address. The address belongs to MAX's file servers, so the bot token is NOT sent there.
     *
     * @return array<string, mixed> what the file server answered (for images it carries the token)
     * @throws PlatformError
     */
    public function uploadFile(string $url, string $path, string $filename): array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new PlatformError(ErrorKind::Permanent, 'The file to send cannot be read.', 'Не удалось прочитать файл для отправки.');
        }
        try {
            $response = $this->http->request('POST', $url, [
                'timeout' => 300,
                'connect_timeout' => 10,
                'http_errors' => false,
                'multipart' => [['name' => 'data', 'contents' => $handle, 'filename' => $filename]],
            ]);
        } catch (GuzzleException $e) {
            throw $this->transportError('upload', $e, false);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
        $status = $response->getStatusCode();
        if ($status >= 500 || $status === 0) {
            throw new PlatformError(ErrorKind::Temporary, sprintf('MAX upload: HTTP %d.', $status), 'MAX сейчас не принимает файлы. Попробуйте позже.');
        }
        if ($status >= 400) {
            throw new PlatformError(ErrorKind::Permanent, sprintf('MAX upload: HTTP %d.', $status), 'MAX не принял файл. Проверьте его формат и размер.');
        }
        $decoded = json_decode((string) $response->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param list<string> $updateTypes
     * @throws PlatformError
     */
    public function subscribe(string $url, array $updateTypes, #[SensitiveParameter] string $secret): void
    {
        $this->expectSuccess($this->call('POST', '/subscriptions', [], ['url' => $url, 'update_types' => $updateTypes, 'secret' => $secret]));
    }

    /**
     * @throws PlatformError
     */
    public function unsubscribe(string $url): void
    {
        $this->expectSuccess($this->call('DELETE', '/subscriptions', ['url' => $url]));
    }

    /**
     * @return list<array<string, mixed>>
     * @throws PlatformError
     */
    public function getSubscriptions(): array
    {
        $result = $this->call('GET', '/subscriptions');

        return $this->items($result['subscriptions'] ?? []);
    }

    /**
     * Long polling for local development.
     *
     * @param list<string> $types
     * @return array{updates: list<array<string, mixed>>, marker: ?int}
     * @throws PlatformError
     */
    public function getUpdates(?int $marker, int $timeoutSeconds, array $types): array
    {
        $query = ['timeout' => $timeoutSeconds, 'limit' => 100, 'types' => implode(',', $types)] + ($marker !== null ? ['marker' => $marker] : []);
        $result = $this->call('GET', '/updates', $query, null, false, $timeoutSeconds + 10);

        return ['updates' => $this->items($result['updates'] ?? []), 'marker' => is_int($result['marker'] ?? null) ? $result['marker'] : null];
    }

    /**
     * @param array<string, scalar> $query
     * @param array<string, mixed>|null $json
     * @param bool $mutating true when a lost response may mean the action happened
     * @return array<string, mixed>
     * @throws PlatformError
     */
    public function call(string $method, string $path, array $query = [], ?array $json = null, bool $mutating = false, int $timeout = 30): array
    {
        $options = ['timeout' => $timeout, 'connect_timeout' => 10, 'http_errors' => false, 'headers' => ['Authorization' => $this->token]];
        if ($query !== []) {
            $options['query'] = $query;
        }
        if ($json !== null) {
            $options['json'] = $json;
        }

        try {
            $response = $this->http->request($method, $this->apiBase . $path, $options);
        } catch (GuzzleException $e) {
            throw $this->transportError($method . ' ' . $path, $e, $mutating);
        }

        $status = $response->getStatusCode();
        $decoded = json_decode((string) $response->getBody(), true);
        if ($status >= 200 && $status < 300) {
            return is_array($decoded) ? $decoded : [];
        }

        throw $this->apiError($method . ' ' . $path, $status, is_array($decoded) ? $decoded : []);
    }

    /**
     * Whether the error is MAX saying "the file is not processed yet, try again in a moment".
     */
    public static function isNotReady(PlatformError $e): bool
    {
        return str_contains($e->getMessage(), self::NOT_READY);
    }

    /**
     * @param array<string, mixed> $result
     * @throws PlatformError
     */
    private function expectSuccess(array $result): void
    {
        // Edit, delete and pin answer HTTP 200 with `success: false` and a reason when they did not work.
        if (($result['success'] ?? true) === false) {
            $reason = is_string($result['message'] ?? null) ? $result['message'] : 'no reason';

            throw new PlatformError(ErrorKind::Permanent, 'MAX refused the action: ' . $reason, 'MAX не выполнил действие. Возможно, у бота не хватает прав или сообщение уже удалено.');
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function items(mixed $list): array
    {
        $items = [];
        foreach (is_array($list) ? $list : [] as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $items[] = $item;
            }
        }

        return $items;
    }

    private function transportError(string $what, GuzzleException $e, bool $mutating): PlatformError
    {
        // A failure to establish the connection proves nothing was sent; anything later may have happened after MAX got the request.
        $kind = $mutating && !$e instanceof ConnectException ? ErrorKind::UnknownOutcome : ErrorKind::Temporary;

        // Deliberately no `$e->getMessage()` and no `previous`: they can carry request details.
        return new PlatformError($kind, sprintf('MAX %s: transport error (%s).', $what, (new \ReflectionClass($e))->getShortName()), 'Не удалось связаться с MAX. Попробуйте позже.');
    }

    /**
     * @param array<string, mixed> $body `{"code": "...", "message": "..."}`
     */
    private function apiError(string $what, int $status, array $body): PlatformError
    {
        $code = is_string($body['code'] ?? null) ? $body['code'] : '';
        $description = is_string($body['message'] ?? null) ? $body['message'] : 'no description';
        $message = sprintf('MAX %s: %d %s %s', $what, $status, $code, $description);

        if ($status === 429) {
            return new PlatformError(ErrorKind::RateLimited, $message, 'MAX просит подождать: слишком много запросов.', 2, $status);
        }
        if ($status >= 500 || $status === 0) {
            return new PlatformError(ErrorKind::Temporary, $message, 'MAX сейчас недоступен. Попробуйте позже.', null, $status);
        }
        if ($status === 401) {
            return new PlatformError(ErrorKind::Auth, $message, 'Токен бота MAX не подошёл. Проверьте, что скопировали его целиком из кабинета бота.', null, $status);
        }
        if ($status === 403) {
            return new PlatformError(ErrorKind::Auth, $message, 'Бот больше не может писать в этот канал: его удалили или забрали права. Добавьте бота администратором снова.', null, $status);
        }
        if ($code === self::NOT_READY || str_contains(strtolower($description), self::NOT_READY)) {
            return new PlatformError(ErrorKind::Temporary, $message, 'MAX ещё обрабатывает загруженный файл.', 3, $status);
        }
        if ($status === 404 || str_contains($code, 'not.found')) {
            return new PlatformError(ErrorKind::Permanent, $message, 'Не нашли такой канал. Проверьте ссылку и что бот добавлен в него администратором.', null, $status);
        }
        if (str_contains($code, 'text') && str_contains(strtolower($description), 'long')) {
            return new PlatformError(ErrorKind::Permanent, $message, 'Текст слишком длинный для MAX.', null, $status);
        }

        return new PlatformError(ErrorKind::Permanent, $message, 'MAX не принял запрос. Проверьте текст и вложения.', null, $status);
    }
}
