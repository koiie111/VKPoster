<?php

declare(strict_types=1);

namespace App\Http\Controllers\Posts;

use App\Domain\Post\PostDraft;
use App\Domain\Post\PostOptions;
use App\Domain\Post\VariantInput;
use App\Kernel\Http\Request;

/**
 * Reads the editor's form (and the JSON the editor sends for validation and autosave, which has the same field names) into a
 * `PostDraft`, tolerantly: anything unexpected is dropped, and the domain validates what is left.
 *
 * Field names: `text`, `media[]`, `channels[]`, `per_network`, the options (`silent`, `disable_preview`, `pin`, `delete_after` with
 * `delete_after_unit`, `first_comment`, `buttons[n][text|url]`), and for one channel the same under `v[<channel id>]` with the
 * switches `text_custom`, `media_custom`, `options_custom`: without a switch the variant follows the post.
 */
final class PostForm
{
    private const UNITS = ['minutes' => 1, 'hours' => 60, 'days' => 1440];

    public function __construct(
        public readonly PostDraft $draft,
        public readonly string $intent,
        public readonly string $date,
        public readonly string $time,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $all = $request->all();
        $channels = [];
        foreach (is_array($all['channels'] ?? null) ? $all['channels'] : [] as $id) {
            if (is_string($id) && preg_match('/^[0-9A-Za-z]{26}$/', $id) === 1) {
                $channels[strtoupper($id)] = true;
            }
        }
        $perNetwork = self::flag($all['per_network'] ?? null);
        $overrides = is_array($all['v'] ?? null) ? $all['v'] : [];
        $variants = [];
        foreach (array_keys($channels) as $id) {
            $own = is_array($overrides[$id] ?? null) ? $overrides[$id] : (is_array($overrides[strtolower($id)] ?? null) ? $overrides[strtolower($id)] : []);
            $variants[] = new VariantInput(
                $id,
                $perNetwork && self::flag($own['text_custom'] ?? null) ? trim(self::string($own['text'] ?? null)) : null,
                $perNetwork && self::flag($own['media_custom'] ?? null) ? self::ids($own['media'] ?? null) : null,
                $perNetwork && self::flag($own['options_custom'] ?? null) ? self::options($own) : null,
            );
        }
        $intent = self::string($all['intent'] ?? null);

        return new self(
            new PostDraft(trim(self::string($all['text'] ?? null)), self::ids($all['media'] ?? null), self::options($all), $perNetwork, $variants),
            in_array($intent, ['draft', 'schedule', 'now'], true) ? $intent : 'draft',
            self::string($all['publish_date'] ?? null),
            self::string($all['publish_time'] ?? null),
        );
    }

    /**
     * @param array<mixed> $source
     */
    private static function options(array $source): PostOptions
    {
        $value = self::string($source['delete_after'] ?? null);
        $unit = self::UNITS[self::string($source['delete_after_unit'] ?? null)] ?? 60;
        $buttons = [];
        foreach (is_array($source['buttons'] ?? null) ? $source['buttons'] : [] as $button) {
            if (is_array($button)) {
                $buttons[] = ['text' => self::string($button['text'] ?? null), 'url' => self::string($button['url'] ?? null)];
            }
        }

        return PostOptions::fromArray([
            'buttons' => $buttons,
            'silent' => self::flag($source['silent'] ?? null),
            'disable_preview' => self::flag($source['disable_preview'] ?? null),
            'pin' => self::flag($source['pin'] ?? null),
            'delete_after_minutes' => $value !== '' && ctype_digit($value) && strlen($value) <= 6 ? (int) $value * $unit : null,
            'first_comment' => self::string($source['first_comment'] ?? null),
        ]);
    }

    /**
     * @return list<string>
     */
    private static function ids(mixed $value): array
    {
        $ids = [];
        foreach (is_array($value) ? $value : [] as $id) {
            if (is_string($id) && preg_match('/^[0-9A-Za-z]{26}$/', $id) === 1 && !in_array(strtoupper($id), $ids, true)) {
                $ids[] = strtoupper($id);
            }
        }

        return $ids;
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? str_replace(["\r\n", "\r"], "\n", $value) : '';
    }

    private static function flag(mixed $value): bool
    {
        return $value === '1' || $value === 1 || $value === true || $value === 'on' || $value === 'true';
    }
}
