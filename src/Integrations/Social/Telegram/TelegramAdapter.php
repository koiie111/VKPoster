<?php

declare(strict_types=1);

namespace App\Integrations\Social\Telegram;

use App\Domain\Media\MediaKind;
use App\Integrations\Social\Contracts\Capabilities;
use App\Integrations\Social\Contracts\ChannelConnector;
use App\Integrations\Social\Contracts\ChannelInfo;
use App\Integrations\Social\Contracts\Credential;
use App\Integrations\Social\Contracts\ErrorKind;
use App\Integrations\Social\Contracts\HealthStatus;
use App\Integrations\Social\Contracts\Platform;
use App\Integrations\Social\Contracts\PlatformAdapter;
use App\Integrations\Social\Contracts\PlatformError;
use App\Integrations\Social\Contracts\PublishMedia;
use App\Integrations\Social\Contracts\PublishRequest;
use App\Integrations\Social\Contracts\PublishResult;

/**
 * Telegram through the Bot API: publishing, deleting, pinning, health checks, and connecting a channel by the customer's own bot token.
 * Telegram has no idempotency keys, so `$idempotencyKey` is ignored; duplicates are prevented by the pipeline never retrying
 * an `unknown_outcome`.
 *
 * Text longer than the caption limit goes out as a separate message right after the media (the buttons then sit under the text).
 */
final class TelegramAdapter implements PlatformAdapter, ChannelConnector
{
    private const MAX_TEXT = 4096;
    private const MAX_CAPTION = 1024;
    private const MAX_PHOTO_BYTES = 10 * 1024 * 1024;
    private const MAX_FILE_BYTES = 50 * 1024 * 1024;

    public function __construct(private readonly TelegramClientFactory $clients, private readonly TelegramInspector $inspector)
    {
    }

    public function platform(): Platform
    {
        return Platform::Telegram;
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(self::MAX_TEXT, self::MAX_CAPTION, 10, true, true, true, true, true, true, false, self::MAX_FILE_BYTES);
    }

    public function validate(PublishRequest $request): array
    {
        $problems = [];
        $text = $request->format === 'html' ? html_entity_decode(strip_tags($request->text), ENT_QUOTES | ENT_HTML5) : $request->text;
        $length = mb_strlen($text);
        $count = count($request->media);

        if ($request->poll !== null) {
            $poll = $request->poll;
            if (mb_strlen($poll['question']) < 1 || mb_strlen($poll['question']) > 300) {
                $problems[] = 'Вопрос опроса в Telegram — до 300 символов.';
            }
            if (count($poll['options']) < 2 || count($poll['options']) > 10) {
                $problems[] = 'В опросе Telegram от 2 до 10 вариантов ответа.';
            }
            foreach ($poll['options'] as $option) {
                if (mb_strlen($option) < 1 || mb_strlen($option) > 100) {
                    $problems[] = 'Вариант ответа в опросе Telegram — до 100 символов.';
                    break;
                }
            }

            return $problems;
        }
        if ($length === 0 && $count === 0) {
            $problems[] = 'Добавьте текст или файл: пустой пост в Telegram не отправить.';
        }
        if ($length > self::MAX_TEXT) {
            $problems[] = sprintf('Текст длиннее лимита Telegram: %d из %d символов.', $length, self::MAX_TEXT);
        }
        if ($count > 10) {
            $problems[] = 'В Telegram можно прикрепить не больше 10 файлов к одному посту.';
        }
        if ($count > 1 && $request->buttons !== [] && $length <= self::MAX_CAPTION) {
            $problems[] = 'К альбому нельзя добавить кнопки: уберите кнопки или оставьте один файл.';
        }
        $documents = count(array_filter($request->media, static fn (PublishMedia $m): bool => $m->kind === MediaKind::Document));
        if ($count > 1 && $documents > 0 && $documents < $count) {
            $problems[] = 'Документы в Telegram нельзя смешивать с фото и видео в одном альбоме.';
        }
        foreach ($request->media as $media) {
            $size = is_file($media->path) ? (int) filesize($media->path) : 0;
            if ($size > self::MAX_FILE_BYTES) {
                $problems[] = sprintf('Файл «%s» больше 50 МБ: Telegram его не примет.', $media->filename);
            } elseif ($media->kind === MediaKind::Image && $size > self::MAX_PHOTO_BYTES) {
                $problems[] = sprintf('Фото «%s» больше 10 МБ: Telegram его не примет как фото.', $media->filename);
            }
        }
        foreach ($request->buttons as $button) {
            if (preg_match('~^https?://~i', $button['url']) !== 1) {
                $problems[] = 'Кнопки в Telegram принимают только ссылки http:// и https://.';
                break;
            }
        }

        return $problems;
    }

    public function publish(PublishRequest $request, string $externalChannelId, Credential $credential, string $idempotencyKey): PublishResult
    {
        $client = $this->clients->make($credential->secret);
        $chat = $this->chatId($externalChannelId);
        $extra = $request->silent ? ['disable_notification' => true] : [];
        $markup = $request->buttons === [] ? [] : ['reply_markup' => ['inline_keyboard' => array_map(
            static fn (array $b): array => [['text' => $b['text'], 'url' => $b['url']]],
            $request->buttons,
        )]];
        $parse = $request->format === 'html' ? ['parse_mode' => 'HTML'] : [];

        if ($request->poll !== null) {
            $poll = $request->poll;
            $message = $client->sendPoll($chat, $poll['question'], $poll['options'], $extra + ['is_anonymous' => $poll['anonymous'], 'allows_multiple_answers' => $poll['multiple']] + $markup);

            return $this->result($externalChannelId, [$message]);
        }

        $count = count($request->media);
        if ($count === 0) {
            return $this->result($externalChannelId, [$client->sendMessage($chat, $request->text, $extra + $parse + $markup)]);
        }

        $visibleLength = mb_strlen($request->format === 'html' ? strip_tags($request->text) : $request->text);
        $captionFits = $visibleLength <= self::MAX_CAPTION;
        $caption = $captionFits && $request->text !== '' ? ['caption' => $request->text] + $parse : [];
        $messages = [];

        if ($count === 1) {
            $media = $request->media[0];
            $messages[] = $this->sendOne($client, $chat, $media, $caption + $extra + ($captionFits ? $markup : []));
        } else {
            $items = [];
            foreach ($request->media as $i => $media) {
                $item = ['type' => $this->mediaType($media), 'path' => $media->path, 'filename' => $media->filename];
                if ($i === 0 && $caption !== []) {
                    $item['caption'] = $request->text;
                    if ($parse !== []) {
                        $item['parse_mode'] = 'HTML';
                    }
                }
                $items[] = $item;
            }
            array_push($messages, ...$client->sendMediaGroup($chat, $items, $extra));
        }
        if (!$captionFits) {
            try {
                $messages[] = $client->sendMessage($chat, $request->text, $extra + $parse + $markup);
            } catch (PlatformError $e) {
                // The media is out already; a retry would post it a second time, so the outcome is "unknown", not "failed".
                throw new PlatformError(ErrorKind::UnknownOutcome, 'Media was sent but the text message failed: ' . $e->getMessage(), 'Файлы опубликованы, а текст отправить не удалось. Проверьте канал.');
            }
        }

        return $this->result($externalChannelId, $messages);
    }

    public function delete(PublishResult $published, string $externalChannelId, Credential $credential): void
    {
        $client = $this->clients->make($credential->secret);
        $chat = $this->chatId($externalChannelId);
        foreach ($published->allIds !== [] ? $published->allIds : [$published->externalId] as $id) {
            $client->deleteMessage($chat, (int) $id);
        }
    }

    public function pin(PublishResult $published, string $externalChannelId, Credential $credential, bool $pin): void
    {
        $client = $this->clients->make($credential->secret);
        $chat = $this->chatId($externalChannelId);
        $pin ? $client->pinChatMessage($chat, (int) $published->externalId) : $client->unpinChatMessage($chat, (int) $published->externalId);
    }

    public function healthCheck(string $externalChannelId, Credential $credential): HealthStatus
    {
        try {
            $info = $this->inspector->inspect($this->clients->make($credential->secret), $this->chatId($externalChannelId), TelegramInspector::botIdFromToken($credential->secret));
        } catch (PlatformError $e) {
            $gone = $e->platformCode === 403 || str_contains(strtolower($e->getMessage()), 'chat not found');

            return $e->concernsChannel() ? HealthStatus::broken($e->forUser(), $gone) : HealthStatus::unknown($e->forUser());
        }

        return HealthStatus::ok($info->rights, $info->title);
    }

    public function connect(Credential $credential, string $reference): ChannelInfo
    {
        $chat = TelegramInspector::parseReference($reference);
        if ($chat === null) {
            throw new PlatformError(ErrorKind::Permanent, 'Unusable channel reference.', 'Не поняли, какой это канал. Укажите его имя вида @mychannel или ссылку t.me/mychannel.');
        }
        $client = $this->clients->make($credential->secret);

        // getMe first: a wrong token must say "wrong token", not "channel not found".
        return $this->inspector->inspect($client, $chat, $this->inspector->botId($client));
    }

    private function chatId(string $externalChannelId): int|string
    {
        return preg_match('/^-?\d+$/', $externalChannelId) === 1 ? (int) $externalChannelId : $externalChannelId;
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function sendOne(TelegramClient $client, int|string $chat, PublishMedia $media, array $extra): array
    {
        return match ($this->mediaType($media)) {
            'photo' => $client->sendPhoto($chat, $media->path, $media->filename, $extra),
            'video' => $client->sendVideo($chat, $media->path, $media->filename, $extra),
            default => $client->sendDocument($chat, $media->path, $media->filename, $extra),
        };
    }

    private function mediaType(PublishMedia $media): string
    {
        return match ($media->kind) {
            MediaKind::Image => 'photo',
            MediaKind::Video => 'video',
            default => 'document',
        };
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    private function result(string $externalChannelId, array $messages): PublishResult
    {
        $ids = [];
        foreach ($messages as $message) {
            if (is_int($message['message_id'] ?? null)) {
                $ids[] = (string) $message['message_id'];
            }
        }
        if ($ids === []) {
            throw new PlatformError(ErrorKind::UnknownOutcome, 'Telegram accepted the request but returned no message id.', 'Telegram принял пост, но не вернул его номер. Проверьте канал.');
        }
        $url = str_starts_with($externalChannelId, '-100') ? 'https://t.me/c/' . substr($externalChannelId, 4) . '/' . $ids[0] : null;

        return new PublishResult($ids[0], $url, $ids);
    }
}
