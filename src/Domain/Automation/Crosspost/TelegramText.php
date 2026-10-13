<?php

declare(strict_types=1);

namespace App\Domain\Automation\Crosspost;

/**
 * Telegram text with formatting entities to the editor's markup (`TextFormatter`): bold, italic, strikethrough and links are kept,
 * everything else becomes plain text. Entity offsets count UTF-16 code units, so an emoji takes two. Markup never crosses a line
 * break and never starts or ends with a space, so entities are cut into lines and trimmed.
 */
final class TelegramText
{
    private const SPECIAL = ['\\', '*', '_', '~', '[', ']', '(', ')'];

    /**
     * @param list<array{type: string, offset: int, length: int, url?: string}> $entities
     */
    public static function toMarkup(string $text, array $entities): string
    {
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) {
            return '';
        }
        $count = count($chars);
        $indexAt = [];
        $unit = 0;
        foreach ($chars as $i => $char) {
            $indexAt[$unit] = $i;
            $unit += strlen($char) >= 4 ? 2 : 1;
        }
        $indexAt[$unit] = $count;

        usort($entities, static fn (array $a, array $b): int => [$a['offset'], -$a['length']] <=> [$b['offset'], -$b['length']]);
        $open = [];
        $close = [];
        foreach ($entities as $entity) {
            [$begin, $end] = [$entity['offset'], $entity['offset'] + $entity['length']];
            [$from, $to] = [$indexAt[$begin] ?? null, $indexAt[$end] ?? null];
            if ($from === null || $to === null || $to <= $from) {
                continue;
            }
            $markers = match ($entity['type']) {
                'bold' => ['**', '**'],
                'italic' => ['_', '_'],
                'strikethrough' => ['~~', '~~'],
                'text_link' => self::linkMarkers($entity['url'] ?? ''),
                default => null,
            };
            if ($markers === null) {
                continue;
            }
            foreach (self::lineSegments($chars, $from, $to) as [$s, $e]) {
                $open[$s][] = $markers[0];
                $close[$e] = array_merge([$markers[1]], $close[$e] ?? []);
            }
        }

        $out = '';
        for ($i = 0; $i <= $count; ++$i) {
            $out .= implode('', $close[$i] ?? []) . implode('', $open[$i] ?? []);
            if ($i < $count) {
                $out .= in_array($chars[$i], self::SPECIAL, true) ? '\\' . $chars[$i] : $chars[$i];
            }
        }

        return $out;
    }

    /**
     * Plain text for a place inside the editor's markup: characters that mean something there are escaped.
     */
    public static function escape(string $text): string
    {
        return preg_replace('/([\\\\*_~\[\]()])/', '\\\\$1', $text) ?? $text;
    }

    /**
     * @return array{string, string}|null
     */
    private static function linkMarkers(string $url): ?array
    {
        if (preg_match('~^https?://[^\s]+$~i', $url) !== 1) {
            return null;
        }

        return ['[', '](' . str_replace(['(', ')'], ['%28', '%29'], $url) . ')'];
    }

    /**
     * @param list<string> $chars
     * @return list<array{int, int}> [from, to) ranges, one per line, without surrounding blanks
     */
    private static function lineSegments(array $chars, int $from, int $to): array
    {
        $segments = [];
        $start = $from;
        for ($i = $from; $i <= $to; ++$i) {
            if ($i === $to || $chars[$i] === "\n") {
                $s = $start;
                $e = $i;
                while ($s < $e && trim($chars[$s]) === '') {
                    ++$s;
                }
                while ($e > $s && trim($chars[$e - 1]) === '') {
                    --$e;
                }
                if ($e > $s) {
                    $segments[] = [$s, $e];
                }
                $start = $i + 1;
            }
        }

        return $segments;
    }
}
