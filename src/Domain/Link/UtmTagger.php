<?php

declare(strict_types=1);

namespace App\Domain\Link;

/**
 * Adds UTM tags to addresses. Pure string work, no storage: the same input always gives the same output.
 *
 * - a tag that the address already carries is never replaced (a person's own `utm_campaign` wins);
 * - the fragment (`#anchor`) and every other parameter stay as they were;
 * - only `http` and `https` addresses are touched;
 * - values are lower-case latin letters, digits, `_`, `-` and `.` (Cyrillic is transliterated), so they are safe in a query string.
 */
final class UtmTagger
{
    /** Finds a link written as `[text](url)` (group 2 is the address) or a bare address (group 3). */
    private const URL_PATTERN = '/\[([^\]\n]+)\]\((https?:\/\/[^\s()]+(?:\([^\s()]*\)[^\s()]*)*)\)|(https?:\/\/[^\s()<>\[\]]+)/u';

    /** Punctuation and closing markers (`**`, `~~`) that stick to a bare address but are not part of it. */
    private const TRAILING = '.,;:!?»”\'"*~';

    private const TRANSLIT = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e', 'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y',
        'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f',
        'х' => 'h', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch', 'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
    ];

    /**
     * @param array<string, string> $vars placeholder name => value, e.g. `['platform' => 'vk', 'channel' => 'Кофемания']`
     */
    public function tagUrl(string $url, UtmRule $rule, array $vars): string
    {
        if (!$rule->enabled || preg_match('~^https?://~i', $url) !== 1) {
            return $url;
        }
        $fragment = '';
        $hash = strpos($url, '#');
        if ($hash !== false) {
            $fragment = substr($url, $hash);
            $url = substr($url, 0, $hash);
        }
        $query = '';
        $mark = strpos($url, '?');
        if ($mark !== false) {
            $query = substr($url, $mark + 1);
            $url = substr($url, 0, $mark);
        }
        $present = [];
        foreach (explode('&', $query) as $pair) {
            $present[strtolower(urldecode(explode('=', $pair, 2)[0]))] = true;
        }
        $add = [];
        foreach ($rule->templates() as $name => $template) {
            $value = self::slug($this->expand($template, $vars));
            if ($value !== '' && !isset($present[$name])) {
                $add[] = $name . '=' . rawurlencode($value);
            }
        }
        $query = implode('&', array_filter([$query, ...$add], static fn (string $q): bool => $q !== ''));

        return $url . ($query === '' ? '' : '?' . $query) . $fragment;
    }

    /**
     * Rewrites every address of a text written in the editor's markup (links and bare addresses) with `$map`, keeping the markup and
     * the punctuation after a bare address as they were.
     *
     * @param callable(string): string $map
     */
    public static function mapUrls(string $text, callable $map): string
    {
        return (string) preg_replace_callback(self::URL_PATTERN, static function (array $m) use ($map): string {
            if ($m[2] !== null) {
                return '[' . $m[1] . '](' . $map($m[2]) . ')';
            }
            $bare = (string) $m[3];
            $tail = '';
            while ($bare !== '' && str_contains(self::TRAILING, substr($bare, -1)) && strlen($bare) > 8) {
                $tail = substr($bare, -1) . $tail;
                $bare = substr($bare, 0, -1);
            }

            return $map($bare) . $tail;
        }, $text, -1, $count, PREG_UNMATCHED_AS_NULL);
    }

    /** Text -> a value that is safe in a query string. */
    public static function slug(string $value): string
    {
        $value = strtr(mb_strtolower($value), self::TRANSLIT);
        $value = (string) preg_replace('/[^a-z0-9_.\-]+/', '_', $value);

        return mb_substr(trim($value, '_'), 0, 80);
    }

    /**
     * @param array<string, string> $vars
     */
    private function expand(string $template, array $vars): string
    {
        return (string) preg_replace_callback('/\{(\w+)\}/', static fn (array $m): string => $vars[$m[1]] ?? '', $template);
    }
}
