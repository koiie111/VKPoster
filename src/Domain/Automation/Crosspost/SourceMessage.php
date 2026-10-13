<?php

declare(strict_types=1);

namespace App\Domain\Automation\Crosspost;

/**
 * One post of a Telegram channel as the cross-posting sees it: text (or the caption of a file), its formatting entities, the
 * files it carries, whether it was forwarded and which album it belongs to. Built from a Bot API `Message`, tolerantly.
 */
final class SourceMessage
{
    /**
     * @param list<array{type: string, offset: int, length: int, url?: string}> $entities
     * @param list<array{kind: string, file_id: string, size: int}> $media photos and videos only
     */
    public function __construct(
        public readonly int $chatId,
        public readonly int $messageId,
        public readonly ?string $groupId,
        public readonly string $text,
        public readonly array $entities,
        public readonly array $media,
        public readonly bool $forwarded,
        public readonly bool $unsupported = false,
    ) {
    }

    /**
     * @param array<string, mixed> $message a Bot API Message
     */
    public static function fromTelegram(array $message): ?self
    {
        $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];
        $chatId = $chat['id'] ?? null;
        $messageId = $message['message_id'] ?? null;
        if (!is_int($chatId) || !is_int($messageId)) {
            return null;
        }
        $text = is_string($message['text'] ?? null) ? $message['text'] : (is_string($message['caption'] ?? null) ? $message['caption'] : '');
        $rawEntities = is_array($message['entities'] ?? null) ? $message['entities'] : (is_array($message['caption_entities'] ?? null) ? $message['caption_entities'] : []);
        $entities = [];
        foreach ($rawEntities as $entity) {
            if (!is_array($entity) || !is_string($entity['type'] ?? null) || !is_int($entity['offset'] ?? null) || !is_int($entity['length'] ?? null)) {
                continue;
            }
            $item = ['type' => $entity['type'], 'offset' => $entity['offset'], 'length' => $entity['length']];
            if (is_string($entity['url'] ?? null)) {
                $item['url'] = $entity['url'];
            }
            $entities[] = $item;
        }
        $media = [];
        if (is_array($message['photo'] ?? null) && $message['photo'] !== []) {
            $largest = null;
            foreach ($message['photo'] as $size) {
                if (is_array($size) && is_string($size['file_id'] ?? null) && ($largest === null || (int) ($size['file_size'] ?? 0) >= (int) ($largest['file_size'] ?? 0))) {
                    $largest = $size;
                }
            }
            if ($largest !== null) {
                $media[] = ['kind' => 'photo', 'file_id' => (string) $largest['file_id'], 'size' => (int) ($largest['file_size'] ?? 0)];
            }
        }
        if (is_array($message['video'] ?? null) && is_string($message['video']['file_id'] ?? null)) {
            $media[] = ['kind' => 'video', 'file_id' => $message['video']['file_id'], 'size' => (int) ($message['video']['file_size'] ?? 0)];
        }
        // Documents, audio, voice, stickers, polls and so on are not carried over: such a post is skipped with a reason.
        $unsupported = $media === [] && $text === '';
        $group = $message['media_group_id'] ?? null;

        return new self(
            $chatId,
            $messageId,
            is_string($group) && $group !== '' ? substr($group, 0, 40) : null,
            $text,
            $entities,
            $media,
            isset($message['forward_origin']) || isset($message['forward_from_chat']) || isset($message['forward_date']) || isset($message['forward_from']),
            $unsupported,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['chat_id' => $this->chatId, 'message_id' => $this->messageId, 'group' => $this->groupId, 'text' => $this->text, 'entities' => $this->entities, 'media' => $this->media, 'forwarded' => $this->forwarded, 'unsupported' => $this->unsupported];
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        if (!is_int($data['chat_id'] ?? null) || !is_int($data['message_id'] ?? null)) {
            return null;
        }
        /** @var list<array{type: string, offset: int, length: int, url?: string}> $entities */
        $entities = is_array($data['entities'] ?? null) ? array_values($data['entities']) : [];
        /** @var list<array{kind: string, file_id: string, size: int}> $media */
        $media = is_array($data['media'] ?? null) ? array_values($data['media']) : [];

        return new self($data['chat_id'], $data['message_id'], is_string($data['group'] ?? null) ? $data['group'] : null, is_string($data['text'] ?? null) ? $data['text'] : '', $entities, $media, ($data['forwarded'] ?? false) === true, ($data['unsupported'] ?? false) === true);
    }
}
