<?php

declare(strict_types=1);

namespace App\Domain\Post;

/**
 * The editor's lightweight markup and its conversion to what each network understands:
 *
 * - `**bold**`, `*italic*` or `_italic_`, `~~strikethrough~~`, `[text](https://link)`; a backslash makes a marker literal (`\*`);
 * - Telegram (and other HTML networks) get `<b> <i> <s> <a>` with everything else escaped;
 * - networks without formatting get plain text, links written as `text (url)`;
 * - counters use `visible()`: the text as the reader sees it (links shown as their text).
 *
 * Markup never crosses a line break, and an unmatched marker stays as typed, so a stray `*` can not swallow a paragraph.
 * `public/assets/js/editor.js` has the same grammar for the live preview; keep both in step (`TextFormatterTest` lists the cases).
 */
final class TextFormatter
{
    private const PATTERN = '/\\\\([\\\\*_~\[\]()])'
        . '|\[([^\]\n]+)\]\((https?:\/\/[^\s()]+(?:\([^\s()]*\)[^\s()]*)*)\)'
        . '|\*\*(?=\S)((?:(?!\*\*(?!\*)).)+?)(?<=\S)\*\*(?!\*)'
        . '|~~(?=\S)((?:(?!~~).)+?)(?<=\S)~~'
        . '|\*(?=[^\s*])([^*\n]+?)(?<=[^\s*])\*'
        . '|(?<![\p{L}\p{N}_])_(?=[^\s_])([^_\n]+?)(?<=[^\s_])_(?![\p{L}\p{N}_])/u';

    public static function toHtml(string $text): string
    {
        return self::render(self::parse($text), 'html');
    }

    public static function toPlain(string $text): string
    {
        return self::render(self::parse($text), 'plain');
    }

    /**
     * The text as the reader sees it: markers gone, links shown as their text.
     */
    public static function visible(string $text): string
    {
        return self::render(self::parse($text), 'visible');
    }

    public static function visibleLength(string $text): int
    {
        return mb_strlen(self::visible($text));
    }

    /**
     * @return list<TextNode>
     */
    private static function parse(string $text): array
    {
        $nodes = [];
        $offset = 0;
        $length = strlen($text);
        while ($offset < $length && preg_match(self::PATTERN, $text, $m, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $offset) === 1) {
            $start = $m[0][1];
            $whole = (string) $m[0][0];
            if ($start > $offset) {
                $nodes[] = new TextNode('t', substr($text, $offset, $start - $offset));
            }
            $offset = $start + strlen($whole);
            $group = static fn (int $i): ?string => isset($m[$i]) && is_string($m[$i][0]) ? $m[$i][0] : null;
            if ($group(1) !== null) {
                $nodes[] = new TextNode('t', $group(1));
            } elseif ($group(2) !== null && $group(3) !== null) {
                $nodes[] = new TextNode('a', $group(3), self::parse($group(2)));
            } elseif ($group(4) !== null) {
                $nodes[] = new TextNode('b', '', self::parse($group(4)));
            } elseif ($group(5) !== null) {
                $nodes[] = new TextNode('s', '', self::parse($group(5)));
            } elseif ($group(6) !== null) {
                $nodes[] = new TextNode('i', '', self::parse($group(6)));
            } elseif ($group(7) !== null) {
                $nodes[] = new TextNode('i', '', self::parse($group(7)));
            }
        }
        if ($offset < $length) {
            $nodes[] = new TextNode('t', substr($text, $offset));
        }

        return $nodes;
    }

    /**
     * @param list<TextNode> $nodes
     */
    private static function render(array $nodes, string $mode): string
    {
        $out = '';
        foreach ($nodes as $node) {
            $inner = self::render($node->children, $mode);
            switch ($node->kind) {
                case 't':
                    $out .= $mode === 'html' ? htmlspecialchars($node->value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $node->value;
                    break;
                case 'a':
                    if ($mode === 'html') {
                        $out .= '<a href="' . htmlspecialchars($node->value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . $inner . '</a>';
                    } elseif ($mode === 'visible') {
                        $out .= $inner;
                    } else {
                        $out .= $inner === $node->value ? $node->value : $inner . ' (' . $node->value . ')';
                    }
                    break;
                default:
                    $out .= $mode === 'html' ? '<' . $node->kind . '>' . $inner . '</' . $node->kind . '>' : $inner;
            }
        }

        return $out;
    }
}
