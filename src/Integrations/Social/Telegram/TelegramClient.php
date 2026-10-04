<?php

declare(strict_types=1);

namespace App\Integrations\Social\Telegram;

use App\Integrations\Social\Contracts\ErrorKind;
use App\Integrations\Social\Contracts\PlatformError;
use App\Kernel\HttpClient\HttpClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use SensitiveParameter;

/**
 * Our own thin client for the Telegram Bot API (one instance per bot token). Every failure becomes a classified
 * `PlatformError`; the token sits in the request URL, so exceptions from the transport are never chained or
 * quoted: nothing that reaches a log or a page can contain it.
 *
 * Methods that send something (`sendMessage`, `sendPhoto`, …) treat a connection that breaks after the request
 * left as `unknown_outcome`: Telegram may already have published it, and a retry would post twice.
 */
final class TelegramClient
{
    public const API_BASE = 'https://api.telegram.org';

    public function __construct(
        private readonly HttpClientInterface $http,
        #[SensitiveParameter]
        private readonly string $token,
        private readonly string $apiBase = self::API_BASE,
    ) {
    }

    /**
     * @return array<string, mixed> the bot itself (id, username, …)
     * @throws PlatformError
     */
    public function getMe(): array
    {
        return $this->call('getMe');
    }

    /**
     * @param int|string $chatId numeric id or `@username`
     * @return array<string, mixed>
     * @throws PlatformError
     */
    public function getChat(int|string $chatId): array
    {
        return $this->call('getChat', ['chat_id' => $chatId]);
    }

    /**
     * @return array<string, mixed>
     * @throws PlatformError
     */
    public function getChatMember(int|string $chatId, int $userId): array
    {
        return $this->call('getChatMember', ['chat_id' => $chatId, 'user_id' => $userId]);
    }

    /**
     * @return list<array<string, mixed>>
     * @throws PlatformError
     */
    public function getChatAdministrators(int|string $chatId): array
    {
        return $this->list($this->call('getChatAdministrators', ['chat_id' => $chatId]));
    }

    /**
     * @param array<string, mixed> $extra parse_mode, reply_markup, disable_notification, …
     * @return array<string, mixed> the sent message
     * @throws PlatformError
     */
    public function sendMessage(int|string $chatId, string $text, array $extra = []): array
    {
        return $this->call('sendMessage', ['chat_id' => $chatId, 'text' => $text] + $extra, [], true);
    }

    /**
     * @param array<string, mixed> $extra caption, parse_mode, reply_markup, …
     * @return array<string, mixed>
     * @throws PlatformError
     */
    public function sendPhoto(int|string $chatId, string $path, string $filename, array $extra = []): array
    {
        return $this->call('sendPhoto', ['chat_id' => $chatId] + $extra, ['photo' => [$path, $filename]], true);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     * @throws PlatformError
     */
    public function sendVideo(int|string $chatId, string $path, string $filename, array $extra = []): array
    {
        return $this->call('sendVideo', ['chat_id' => $chatId, 'supports_streaming' => true] + $extra, ['video' => [$path, $filename]], true);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     * @throws PlatformError
     */
    public function sendDocument(int|string $chatId, string $path, string $filename, array $extra = []): array
    {
        return $this->call('sendDocument', ['chat_id' => $chatId] + $extra, ['document' => [$path, $filename]], true);
    }

    /**
     * An album of 2–10 items sent as one operation. The caption of the first item becomes the album caption.
     *
     * @param list<array{type: string, path: string, filename: string, caption?: string, parse_mode?: string}> $items type is photo | video | document
     * @param array<string, mixed> $extra disable_notification, …
     * @return list<array<string, mixed>> the sent messages
     * @throws PlatformError
     */
    public function sendMediaGroup(int|string $chatId, array $items, array $extra = []): array
    {
        $media = [];
        $files = [];
        foreach ($items as $i => $item) {
            $entry = ['type' => $item['type'], 'media' => 'attach://file' . $i];
            if (isset($item['caption']) && $item['caption'] !== '') {
                $entry['caption'] = $item['caption'];
                if (isset($item['parse_mode'])) {
                    $entry['parse_mode'] = $item['parse_mode'];
                }
            }
            $media[] = $entry;
            $files['file' . $i] = [$item['path'], $item['filename']];
        }

        return $this->list($this->call('sendMediaGroup', ['chat_id' => $chatId, 'media' => $media] + $extra, $files, true));
    }

    /**
     * @param list<string> $options
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     * @throws PlatformError
     */
    public function sendPoll(int|string $chatId, string $question, array $options, array $extra = []): array
    {
        return $this->call('sendPoll', ['chat_id' => $chatId, 'question' => $question, 'options' => array_map(static fn (string $o): array => ['text' => $o], $options)] + $extra, [], true);
    }

    /**
     * @throws PlatformError
     */
    public function pinChatMessage(int|string $chatId, int $messageId, bool $silent = true): void
    {
        $this->call('pinChatMessage', ['chat_id' => $chatId, 'message_id' => $messageId, 'disable_notification' => $silent], [], true);
    }

    /**
     * @throws PlatformError
     */
    public function unpinChatMessage(int|string $chatId, int $messageId): void
    {
        $this->call('unpinChatMessage', ['chat_id' => $chatId, 'message_id' => $messageId], [], true);
    }

    /**
     * @throws PlatformError
     */
    public function deleteMessage(int|string $chatId, int $messageId): void
    {
        $this->call('deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId], [], true);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed> the edited message
     * @throws PlatformError
     */
    public function editMessageText(int|string $chatId, int $messageId, string $text, array $extra = []): array
    {
        return $this->call('editMessageText', ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text] + $extra, [], true);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed> the edited message
     * @throws PlatformError
     */
    public function editMessageCaption(int|string $chatId, int $messageId, string $caption, array $extra = []): array
    {
        return $this->call('editMessageCaption', ['chat_id' => $chatId, 'message_id' => $messageId, 'caption' => $caption] + $extra, [], true);
    }

    /**
     * @param list<string> $allowedUpdates
     * @throws PlatformError
     */
    public function setWebhook(string $url, string $secretToken, array $allowedUpdates): void
    {
        $this->call('setWebhook', ['url' => $url, 'secret_token' => $secretToken, 'allowed_updates' => $allowedUpdates, 'drop_pending_updates' => false]);
    }

    /**
     * @throws PlatformError
     */
    public function deleteWebhook(): void
    {
        $this->call('deleteWebhook');
    }

    /**
     * @return array<string, mixed>
     * @throws PlatformError
     */
    public function getWebhookInfo(): array
    {
        return $this->call('getWebhookInfo');
    }

    /**
     * Long polling for local development.
     *
     * @param list<string> $allowedUpdates
     * @return list<array<string, mixed>>
     * @throws PlatformError
     */
    public function getUpdates(int $offset, int $timeoutSeconds, array $allowedUpdates): array
    {
        return $this->list($this->call('getUpdates', ['offset' => $offset, 'timeout' => $timeoutSeconds, 'allowed_updates' => $allowedUpdates], [], false, $timeoutSeconds + 10));
    }

    /**
     * Path of a file on Telegram's servers, for `downloadFile()`.
     *
     * @throws PlatformError
     */
    public function getFilePath(string $fileId): string
    {
        $file = $this->call('getFile', ['file_id' => $fileId]);

        $path = $file['file_path'] ?? null;
        // The path is appended to a URL that contains the token: accept only what a real file path looks like.
        if (!is_string($path) || str_contains($path, '..') || preg_match('~^[A-Za-z0-9_\-]+(?:/[A-Za-z0-9_\-.]+)*$~', $path) !== 1) {
            throw new PlatformError(ErrorKind::Permanent, 'Telegram returned no usable file path.');
        }

        return $path;
    }

    /**
     * @throws PlatformError
     */
    public function downloadFile(string $filePath, int $maxBytes): string
    {
        try {
            $response = $this->http->request('GET', $this->apiBase . '/file/bot' . $this->token . '/' . $filePath, ['timeout' => 20, 'http_errors' => false]);
        } catch (GuzzleException) {
            throw new PlatformError(ErrorKind::Temporary, 'Could not download a file from Telegram.', 'Не удалось связаться с Telegram.');
        }
        $body = (string) $response->getBody();
        if ($response->getStatusCode() !== 200 || strlen($body) > $maxBytes) {
            throw new PlatformError(ErrorKind::Permanent, 'Telegram file download failed.');
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, array{0: string, 1: string}> $files field name => [local path, file name]
     * @param bool $mutating true when a lost response may mean the action happened
     * @return array<string, mixed>
     * @throws PlatformError
     */
    public function call(string $method, array $params = [], array $files = [], bool $mutating = false, int $timeout = 30): array
    {
        $options = ['timeout' => $timeout, 'connect_timeout' => 10, 'http_errors' => false];
        $handles = [];
        if ($files === []) {
            $options['json'] = $params;
        } else {
            $parts = [];
            foreach ($params as $name => $value) {
                $parts[] = ['name' => $name, 'contents' => is_scalar($value) ? (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value) : json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)];
            }
            foreach ($files as $name => [$path, $filename]) {
                $handle = @fopen($path, 'rb');
                if ($handle === false) {
                    throw new PlatformError(ErrorKind::Permanent, 'The file to send cannot be read.', 'Не удалось прочитать файл для отправки.');
                }
                $handles[] = $handle;
                $parts[] = ['name' => $name, 'contents' => $handle, 'filename' => $filename];
            }
            $options['multipart'] = $parts;
        }

        try {
            $response = $this->http->request('POST', $this->apiBase . '/bot' . $this->token . '/' . $method, $options);
        } catch (GuzzleException $e) {
            throw $this->transportError($method, $e, $mutating);
        } finally {
            foreach ($handles as $handle) {
                fclose($handle);
            }
        }

        $status = $response->getStatusCode();
        $decoded = json_decode((string) $response->getBody(), true);
        if (!is_array($decoded)) {
            // An HTML error page from a gateway, an empty body: whatever it was, Telegram did not answer properly.
            throw new PlatformError($status >= 500 || $status === 0 ? ErrorKind::Temporary : ErrorKind::Permanent, sprintf('Telegram %s: unreadable answer (HTTP %d).', $method, $status), 'Telegram ответил что-то непонятное. Попробуйте позже.');
        }
        if (($decoded['ok'] ?? false) === true) {
            $result = $decoded['result'] ?? [];

            return is_array($result) ? $result : ['value' => $result];
        }

        throw $this->apiError($method, $status, $decoded);
    }

    /**
     * @param array<string, mixed> $result
     * @return list<array<string, mixed>>
     */
    private function list(array $result): array
    {
        $items = [];
        foreach ($result as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $items[] = $item;
            }
        }

        return $items;
    }

    private function transportError(string $method, GuzzleException $e, bool $mutating): PlatformError
    {
        // A failure to establish the connection (DNS, refused, TLS, connect timeout) proves nothing was sent. Any other failure
        // (a timeout while waiting for the answer, a dropped connection) may have happened after Telegram received the request.
        $notSent = $e instanceof ConnectException;
        $kind = $mutating && !$notSent ? ErrorKind::UnknownOutcome : ErrorKind::Temporary;

        // Deliberately no `$e->getMessage()` and no `previous`: Guzzle puts the full URL, and with it the bot token, into them.
        return new PlatformError($kind, sprintf('Telegram %s: transport error (%s).', $method, (new \ReflectionClass($e))->getShortName()), 'Не удалось связаться с Telegram. Попробуйте позже.');
    }

    /**
     * @param array<string, mixed> $body
     */
    private function apiError(string $method, int $status, array $body): PlatformError
    {
        $code = is_int($body['error_code'] ?? null) ? $body['error_code'] : $status;
        $description = is_string($body['description'] ?? null) ? $body['description'] : 'no description';
        $parameters = is_array($body['parameters'] ?? null) ? $body['parameters'] : [];
        $retryAfter = is_int($parameters['retry_after'] ?? null) ? $parameters['retry_after'] : null;
        $text = strtolower($description);
        $message = sprintf('Telegram %s: %d %s', $method, $code, $description);

        if ($code === 429) {
            return new PlatformError(ErrorKind::RateLimited, $message, 'Telegram просит подождать: слишком много запросов.', $retryAfter ?? 5, $code);
        }
        if ($code >= 500) {
            return new PlatformError(ErrorKind::Temporary, $message, 'Telegram сейчас недоступен. Попробуйте позже.', null, $code);
        }
        if ($code === 401) {
            return new PlatformError(ErrorKind::Auth, $message, 'Токен бота не подошёл. Проверьте, что скопировали его целиком из @BotFather.', null, $code);
        }
        if ($code === 403) {
            return new PlatformError(ErrorKind::Auth, $message, 'Бот больше не может писать в этот канал: его удалили или забрали права. Добавьте бота администратором снова.', null, $code);
        }
        if (str_contains($text, 'chat not found') || str_contains($text, 'channel_private')) {
            return new PlatformError(ErrorKind::Permanent, $message, 'Не нашли такой канал. Проверьте имя и что бот добавлен в него администратором.', null, $code);
        }
        if (str_contains($text, 'not enough rights') || str_contains($text, 'need administrator rights') || str_contains($text, 'have no rights')) {
            return new PlatformError(ErrorKind::Auth, $message, 'У бота не хватает прав в канале. Включите ему право «Публикация сообщений».', null, $code);
        }
        if (str_contains($text, 'message is too long') || str_contains($text, 'caption is too long')) {
            return new PlatformError(ErrorKind::Permanent, $message, 'Текст слишком длинный для Telegram.', null, $code);
        }
        if (str_contains($text, "can't parse entities")) {
            return new PlatformError(ErrorKind::Permanent, $message, 'Telegram не смог разобрать оформление текста.', null, $code);
        }

        return new PlatformError(ErrorKind::Permanent, $message, 'Telegram не принял запрос. Проверьте текст и вложения.', null, $code);
    }
}
