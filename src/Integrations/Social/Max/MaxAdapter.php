<?php

declare(strict_types=1);

namespace App\Integrations\Social\Max;

use App\Domain\Media\MediaKind;
use App\Integrations\Social\Contracts\Capabilities;
use App\Integrations\Social\Contracts\ChannelConnector;
use App\Integrations\Social\Contracts\ChannelInfo;
use App\Integrations\Social\Contracts\Credential;
use App\Integrations\Social\Contracts\EditableAdapter;
use App\Integrations\Social\Contracts\ErrorKind;
use App\Integrations\Social\Contracts\HealthStatus;
use App\Integrations\Social\Contracts\Platform;
use App\Integrations\Social\Contracts\PlatformAdapter;
use App\Integrations\Social\Contracts\PlatformError;
use App\Integrations\Social\Contracts\PublishMedia;
use App\Integrations\Social\Contracts\PublishRequest;
use App\Integrations\Social\Contracts\PublishResult;
use Closure;

/**
 * MAX through the bot API: one message per post with text (up to 4000 characters), files and a keyboard of link buttons; deleting,
 * pinning, editing the text, health checks, and connecting a chat with the customer's own bot token.
 *
 * Files are uploaded first. MAX processes them asynchronously and answers `attachment.not.ready` to a message that uses a file too
 * early, so the send is repeated with growing pauses. MAX has no idempotency keys, so `$idempotencyKey` is ignored; duplicates are
 * prevented by the pipeline never retrying an `unknown_outcome`. Polls do not exist in MAX.
 */
final class MaxAdapter implements PlatformAdapter, ChannelConnector, EditableAdapter
{
    private const MAX_TEXT = 4000;
    private const MAX_ATTACHMENTS = 10;
    private const MAX_BUTTONS = 30;
    private const MAX_IMAGE_BYTES = 50 * 1024 * 1024;
    private const MAX_VIDEO_BYTES = 250 * 1024 * 1024;
    private const MAX_FILE_BYTES = 2 * 1024 * 1024 * 1024;
    /** Pauses (seconds) between attempts to send a message whose files are still being processed; about a minute in all. */
    private const NOT_READY_PAUSES = [1, 2, 4, 8, 15, 30];

    /** @var Closure(int): void */
    private readonly Closure $sleep;

    /**
     * @param (Closure(int): void)|null $sleep replaces `sleep()` in tests
     */
    public function __construct(
        private readonly MaxClientFactory $clients,
        private readonly MaxInspector $inspector,
        private readonly MaxRateGate $gate,
        ?Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    public function platform(): Platform
    {
        return Platform::Max;
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(self::MAX_TEXT, self::MAX_TEXT, self::MAX_ATTACHMENTS, false, false, true, true, true, true, false, self::MAX_VIDEO_BYTES, true, 'html');
    }

    public function validate(PublishRequest $request): array
    {
        $problems = [];
        $text = $request->format === 'html' ? html_entity_decode(strip_tags($request->text), ENT_QUOTES | ENT_HTML5) : $request->text;
        $length = mb_strlen($text);
        $count = count($request->media);

        if ($request->poll !== null) {
            return ['MAX не поддерживает опросы: уберите опрос для этого канала.'];
        }
        if ($length === 0 && $count === 0) {
            $problems[] = 'Добавьте текст или файл: пустой пост в MAX не отправить.';
        }
        if ($length > self::MAX_TEXT) {
            $problems[] = sprintf('Текст длиннее лимита MAX: %d из %d символов.', $length, self::MAX_TEXT);
        }
        if ($count > self::MAX_ATTACHMENTS) {
            $problems[] = 'В MAX можно прикрепить не больше 10 файлов к одному посту.';
        }
        foreach ($request->media as $media) {
            $size = is_file($media->path) ? (int) filesize($media->path) : 0;
            $limit = match ($media->kind) {
                MediaKind::Image => self::MAX_IMAGE_BYTES,
                MediaKind::Video => self::MAX_VIDEO_BYTES,
                MediaKind::Document => self::MAX_FILE_BYTES,
            };
            if ($size > $limit) {
                $problems[] = sprintf('Файл «%s» больше %d МБ: MAX его не примет.', $media->filename, intdiv($limit, 1024 * 1024));
            }
        }
        if (count($request->buttons) > self::MAX_BUTTONS) {
            $problems[] = 'В MAX можно добавить не больше 30 кнопок под постом.';
        }
        foreach ($request->buttons as $button) {
            if (preg_match('~^https?://~i', $button['url']) !== 1) {
                $problems[] = 'Кнопки в MAX принимают только ссылки http:// и https://.';
                break;
            }
            if (mb_strlen($button['text']) > 128) {
                $problems[] = 'Надпись на кнопке в MAX — до 128 символов.';
                break;
            }
        }

        return $problems;
    }

    public function publish(PublishRequest $request, string $externalChannelId, Credential $credential, string $idempotencyKey): PublishResult
    {
        $client = $this->clients->make($credential->secret);
        $chat = $this->chatId($externalChannelId);

        $attachments = [];
        foreach ($request->media as $media) {
            $attachments[] = $this->upload($client, $media);
        }
        if ($request->buttons !== []) {
            $attachments[] = ['type' => 'inline_keyboard', 'payload' => ['buttons' => array_map(
                static fn (array $b): array => [['type' => 'link', 'text' => $b['text'], 'url' => $b['url']]],
                $request->buttons,
            )]];
        }

        $body = [];
        if ($request->text !== '') {
            $body['text'] = $request->text;
            if ($request->format === 'html') {
                $body['format'] = 'html';
            }
        }
        if ($attachments !== []) {
            $body['attachments'] = $attachments;
        }

        try {
            $message = $this->send($client, $credential->secret, $chat, $body + ($request->silent ? ['notify' => false] : []), $request->disablePreview);
        } catch (PlatformError $e) {
            // A channel insists on notifications and refuses a quiet post with HTTP 400. Nothing was posted then, so sending it
            // loudly is safe, and better than losing the post.
            if (!$request->silent || $e->kind !== ErrorKind::Permanent || $e->platformCode !== 400) {
                throw $e;
            }
            $message = $this->send($client, $credential->secret, $chat, $body, $request->disablePreview);
        }

        return $this->result($message);
    }

    public function delete(PublishResult $published, string $externalChannelId, Credential $credential): void
    {
        $client = $this->clients->make($credential->secret);
        foreach ($published->allIds !== [] ? $published->allIds : [$published->externalId] as $id) {
            $client->deleteMessage($id);
        }
    }

    /**
     * Change the text of a published post. Files and buttons stay as they are: MAX replaces the whole attachment list when one
     * is sent, so leaving it out is the only way to keep the files.
     */
    public function edit(PublishResult $published, string $externalChannelId, Credential $credential, PublishRequest $request, bool $hasMedia): void
    {
        $body = ['text' => $request->text];
        if ($request->format === 'html') {
            $body['format'] = 'html';
        }
        $this->clients->make($credential->secret)->editMessage($published->externalId, $body);
    }

    public function pin(PublishResult $published, string $externalChannelId, Credential $credential, bool $pin): void
    {
        $client = $this->clients->make($credential->secret);
        $chat = $this->chatId($externalChannelId);
        // MAX keeps one pinned message per chat: unpinning removes it, whichever it is.
        $pin ? $client->pinMessage($chat, $published->externalId) : $client->unpinMessage($chat);
    }

    public function healthCheck(string $externalChannelId, Credential $credential): HealthStatus
    {
        try {
            $client = $this->clients->make($credential->secret);
            $chat = $this->chatId($externalChannelId);
            $info = $client->getChat($chat);
            MaxInspector::assertActive($info);
            $type = is_string($info['type'] ?? null) ? $info['type'] : 'channel';
            $rights = MaxInspector::rights($client->getMembership($chat), $type);
            if (!$rights['post']) {
                return HealthStatus::broken('У бота не хватает права публиковать сообщения в этом канале. Включите ему это право в списке администраторов.');
            }
        } catch (PlatformError $e) {
            $gone = $e->platformCode === 403 || $e->platformCode === 404;

            return $e->concernsChannel() ? HealthStatus::broken($e->forUser(), $gone) : HealthStatus::unknown($e->forUser());
        }

        return HealthStatus::ok($rights, is_string($info['title'] ?? null) ? $info['title'] : null);
    }

    public function connect(Credential $credential, string $reference): ChannelInfo
    {
        $chat = MaxInspector::parseReference($reference);
        if ($chat === null) {
            throw new PlatformError(ErrorKind::Permanent, 'Unusable channel reference.', 'Не поняли, какой это канал. Укажите ссылку вида max.ru/mychannel или номер канала. Ссылка-приглашение не подойдёт.');
        }
        $client = $this->clients->make($credential->secret);
        // /me first: a wrong token must say "wrong token", not "channel not found".
        $client->getMe();

        return $this->inspector->inspect($client, $chat);
    }

    private function chatId(string $externalChannelId): int
    {
        if (preg_match('/^-?\d+$/', $externalChannelId) !== 1) {
            throw new PlatformError(ErrorKind::Permanent, 'MAX channel id is not numeric.', 'Номер канала в MAX записан неверно. Подключите канал заново.');
        }

        return (int) $externalChannelId;
    }

    /**
     * Upload one file and return the attachment to put into the message.
     *
     * @return array{type: string, payload: array{token: string}}
     * @throws PlatformError
     */
    private function upload(MaxClient $client, PublishMedia $media): array
    {
        $type = match ($media->kind) {
            MediaKind::Image => 'image',
            MediaKind::Video => 'video',
            default => 'file',
        };
        $slot = $client->uploadSlot($type);
        $answer = $client->uploadFile($slot['url'], $media->path, $media->filename);

        // Video and files get their token with the slot; an image gets it from the file server once the bytes are there.
        $token = $slot['token'];
        if (is_string($answer['token'] ?? null)) {
            $token = $answer['token'];
        }
        if ($token === null && is_array($answer['photos'] ?? null)) {
            foreach ($answer['photos'] as $photo) {
                if (is_array($photo) && is_string($photo['token'] ?? null)) {
                    $token = $photo['token'];
                    break;
                }
            }
        }
        if ($token === null || $token === '') {
            throw new PlatformError(ErrorKind::Temporary, 'MAX gave no attachment token after the upload.', 'MAX не подтвердил загрузку файла «' . $media->filename . '». Попробуйте позже.');
        }

        return ['type' => $type, 'payload' => ['token' => $token]];
    }

    /**
     * Send the message, repeating while MAX is still processing an uploaded file. A "not ready" answer means nothing was posted, so
     * waiting and asking again cannot duplicate the post.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     * @throws PlatformError
     */
    private function send(MaxClient $client, string $token, int $chat, array $body, bool $disablePreview): array
    {
        $hasFiles = is_array($body['attachments'] ?? null) && array_filter($body['attachments'], static fn (array $a): bool => $a['type'] !== 'inline_keyboard') !== [];
        $pauses = $hasFiles ? self::NOT_READY_PAUSES : [];
        for ($attempt = 0;; ++$attempt) {
            $this->gate->wait($token, $chat);
            try {
                return $client->sendMessage($chat, $body, $disablePreview);
            } catch (PlatformError $e) {
                if (!MaxClient::isNotReady($e)) {
                    throw $e;
                }
                if (!isset($pauses[$attempt])) {
                    throw new PlatformError(ErrorKind::Temporary, $e->getMessage(), 'MAX слишком долго обрабатывает загруженные файлы. Попробуем ещё раз позже.', 30, $e->platformCode);
                }
                ($this->sleep)($pauses[$attempt]);
            }
        }
    }

    /**
     * @param array<string, mixed> $message
     */
    private function result(array $message): PublishResult
    {
        $body = is_array($message['body'] ?? null) ? $message['body'] : [];
        $mid = $body['mid'] ?? null;
        if (!is_string($mid) || $mid === '') {
            throw new PlatformError(ErrorKind::UnknownOutcome, 'MAX accepted the request but returned no message id.', 'MAX принял пост, но не вернул его номер. Проверьте канал.');
        }
        $url = is_string($message['url'] ?? null) && preg_match('~^https://~i', $message['url']) === 1 ? $message['url'] : null;

        return new PublishResult($mid, $url, [$mid]);
    }
}
