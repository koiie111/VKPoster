<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A small, safe Markdown to HTML converter for the documents we write ourselves (legal pages, help articles).
 *
 * Supported: `#`..`####` headings, paragraphs, `-`/`*` and `1.` lists, `>` quotes, fenced code, `---`, tables are not supported.
 * Inline: `**bold**`, `*italic*`, `` `code` ``, `[text](url)`. All text is escaped first, so raw HTML in a source file is shown as text,
 * and a link may only point to `http(s)`, `mailto:` or a path on this site, which keeps `javascript:` out.
 */
final class Markdown
{
    /**
     * Split a leading `---` block of `key: value` lines from the body.
     *
     * @return array{0: array<string, string>, 1: string} meta and body
     */
    public static function frontMatter(string $source): array
    {
        $source = str_replace(["\r\n", "\r"], "\n", $source);
        if (!str_starts_with($source, "---\n")) {
            return [[], $source];
        }
        $end = strpos($source, "\n---\n", 4);
        if ($end === false) {
            return [[], $source];
        }
        $meta = [];
        foreach (explode("\n", substr($source, 4, $end - 4)) as $line) {
            if (preg_match('/^([a-z_]+):\s*(.*)$/', $line, $m) === 1) {
                $meta[$m[1]] = trim($m[2]);
            }
        }

        return [$meta, ltrim(substr($source, $end + 5), "\n")];
    }

    /**
     * @return string HTML (safe to print without escaping)
     */
    public static function toHtml(string $markdown): string
    {
        return (new self())->convert($markdown);
    }

    /** @var list<string> */
    private array $html = [];

    /** @var list<string> */
    private array $paragraph = [];

    /** @var list<string> */
    private array $quote = [];

    private ?string $list = null;

    private function convert(string $markdown): string
    {
        $code = null;
        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $markdown)) as $line) {
            $trimmed = trim($line);
            if ($code !== null) {
                if (str_starts_with($trimmed, '```')) {
                    $this->html[] = self::codeBlock($code);
                    $code = null;
                } else {
                    $code[] = $line;
                }
                continue;
            }
            if (str_starts_with($trimmed, '```')) {
                $this->closeAll();
                $code = [];
            } elseif ($trimmed === '') {
                $this->closeAll();
            } elseif (preg_match('/^(#{1,4})\s+(.+)$/', $trimmed, $m) === 1) {
                $this->closeAll();
                $level = strlen($m[1]);
                $text = trim($m[2]);
                $this->html[] = sprintf('<h%d id="%s">%s</h%d>', $level, self::slug($text), self::inline($text), $level);
            } elseif (preg_match('/^(?:-{3,}|\*{3,})$/', $trimmed) === 1) {
                $this->closeAll();
                $this->html[] = '<hr>';
            } elseif (preg_match('/^([-*]|\d+[.)])\s+(.+)$/', $trimmed, $m) === 1) {
                $this->flushParagraph();
                $this->flushQuote();
                $kind = ctype_digit($m[1][0]) ? 'ol' : 'ul';
                if ($this->list !== $kind) {
                    $this->closeList();
                    $this->html[] = '<' . $kind . '>';
                    $this->list = $kind;
                }
                $this->html[] = '<li>' . self::inline($m[2]) . '</li>';
            } elseif (str_starts_with($trimmed, '>')) {
                $this->flushParagraph();
                $this->closeList();
                $this->quote[] = trim(substr($trimmed, 1));
            } else {
                $this->closeList();
                $this->flushQuote();
                $this->paragraph[] = $trimmed;
            }
        }
        if ($code !== null) {
            $this->html[] = self::codeBlock($code);
        }
        $this->closeAll();

        return implode("\n", $this->html);
    }

    /**
     * @param list<string> $lines
     */
    private static function codeBlock(array $lines): string
    {
        return '<pre><code>' . htmlspecialchars(implode("\n", $lines), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code></pre>';
    }

    private function closeAll(): void
    {
        $this->flushParagraph();
        $this->closeList();
        $this->flushQuote();
    }

    private function flushParagraph(): void
    {
        if ($this->paragraph !== []) {
            $this->html[] = '<p>' . self::inline(implode(' ', $this->paragraph)) . '</p>';
            $this->paragraph = [];
        }
    }

    private function flushQuote(): void
    {
        if ($this->quote !== []) {
            $this->html[] = '<blockquote><p>' . self::inline(implode(' ', $this->quote)) . '</p></blockquote>';
            $this->quote = [];
        }
    }

    private function closeList(): void
    {
        if ($this->list !== null) {
            $this->html[] = '</' . $this->list . '>';
            $this->list = null;
        }
    }

    /**
     * Headings of level 2 and 3, for a table of contents.
     *
     * @return list<array{level: int, id: string, text: string}>
     */
    public static function headings(string $markdown): array
    {
        $result = [];
        $inCode = false;
        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $markdown)) as $line) {
            if (str_starts_with(trim($line), '```')) {
                $inCode = !$inCode;
                continue;
            }
            if (!$inCode && preg_match('/^(#{2,3})\s+(.+)$/', trim($line), $m) === 1) {
                $text = trim(preg_replace('/[*`]/', '', $m[2]) ?? $m[2]);
                $result[] = ['level' => strlen($m[1]), 'id' => self::slug($m[2]), 'text' => $text];
            }
        }

        return $result;
    }

    /**
     * A URL-safe id for a heading (Cyrillic is transliterated away to keep ids ASCII, with a numeric suffix from the text hash when empty).
     */
    public static function slug(string $text): string
    {
        $text = mb_strtolower(preg_replace('/[*`\[\]()]/', '', $text) ?? $text);
        $map = ['а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e', 'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'c', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch', 'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya'];
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtr($text, $map)), '-');

        return $slug === '' ? 's' . substr(md5($text), 0, 6) : $slug;
    }

    private static function inline(string $text): string
    {
        $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        // Code spans first, so their content is not touched by the other rules.
        $stash = [];
        $text = (string) preg_replace_callback('/`([^`]+)`/', static function (array $m) use (&$stash): string {
            $stash[] = '<code>' . $m[1] . '</code>';

            return "\x00" . (count($stash) - 1) . "\x00";
        }, $text);
        $text = (string) preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', static function (array $m): string {
            $url = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
            if (preg_match('#^(https?://|mailto:|/(?!/)|\#)#i', $url) !== 1) {
                return $m[1];
            }
            $external = preg_match('#^https?://#i', $url) === 1;

            return '<a href="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"' . ($external ? ' rel="noopener noreferrer" target="_blank"' : '') . '>' . $m[1] . '</a>';
        }, $text);
        $text = (string) preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
        $text = (string) preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/', '<em>$1</em>', $text);

        return (string) preg_replace_callback("/\x00(\\d+)\x00/", static fn (array $m): string => $stash[(int) $m[1]], $text);
    }
}
