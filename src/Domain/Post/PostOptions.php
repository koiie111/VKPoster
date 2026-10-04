<?php

declare(strict_types=1);

namespace App\Domain\Post;

/**
 * The extras of a post or of one variant: link buttons, silent sending, no link preview, pinning, deleting after a while, and
 * the author's first comment. Always built through `fromArray()`, which accepts whatever the form or the database holds and
 * keeps only valid values, so nothing unchecked reaches an adapter.
 */
final class PostOptions
{
    public const MAX_BUTTONS = 3;
    public const MAX_DELETE_MINUTES = 60 * 24 * 365;

    /**
     * @param list<array{text: string, url: string}> $buttons
     */
    public function __construct(
        public readonly array $buttons = [],
        public readonly bool $silent = false,
        public readonly bool $disablePreview = false,
        public readonly bool $pin = false,
        public readonly ?int $deleteAfterMinutes = null,
        public readonly string $firstComment = '',
    ) {
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $buttons = [];
        $raw = $data['buttons'] ?? [];
        foreach (is_array($raw) ? $raw : [] as $button) {
            if (!is_array($button)) {
                continue;
            }
            $text = is_string($button['text'] ?? null) ? trim($button['text']) : '';
            $url = is_string($button['url'] ?? null) ? trim($button['url']) : '';
            if ($text === '' && $url === '') {
                continue;
            }
            $buttons[] = ['text' => mb_substr($text, 0, 64), 'url' => mb_substr($url, 0, 2000)];
        }
        $minutes = $data['delete_after_minutes'] ?? null;
        $minutes = is_numeric($minutes) ? (int) $minutes : null;
        $comment = is_string($data['first_comment'] ?? null) ? trim($data['first_comment']) : '';

        return new self(
            array_slice($buttons, 0, self::MAX_BUTTONS + 5),
            self::flag($data['silent'] ?? false),
            self::flag($data['disable_preview'] ?? false),
            self::flag($data['pin'] ?? false),
            $minutes !== null && $minutes > 0 ? min($minutes, self::MAX_DELETE_MINUTES) : null,
            mb_substr($comment, 0, 4000),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'buttons' => $this->buttons,
            'silent' => $this->silent,
            'disable_preview' => $this->disablePreview,
            'pin' => $this->pin,
            'delete_after_minutes' => $this->deleteAfterMinutes,
            'first_comment' => $this->firstComment,
        ];
    }

    /**
     * Problems a person can fix, in Russian. Platform-specific limits are the adapter's business.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];
        if (count($this->buttons) > self::MAX_BUTTONS) {
            $problems[] = sprintf('Кнопок может быть не больше %d.', self::MAX_BUTTONS);
        }
        foreach ($this->buttons as $button) {
            if ($button['text'] === '') {
                $problems[] = 'У каждой кнопки должна быть надпись.';
                break;
            }
            if (preg_match('~^https?://[^\s]+$~i', $button['url']) !== 1) {
                $problems[] = 'Ссылка кнопки «' . $button['text'] . '» должна начинаться с http:// или https://.';
                break;
            }
        }

        return $problems;
    }

    private static function flag(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'on' || $value === 'true';
    }
}
