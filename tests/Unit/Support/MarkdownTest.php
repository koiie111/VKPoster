<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Support\Markdown;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Markdown::class)]
final class MarkdownTest extends TestCase
{
    public function testBlocksAndInlineFormatting(): void
    {
        $html = Markdown::toHtml("# Заголовок\n\nТекст с **жирным**, *курсивом* и `кодом`.\n\n- один\n- два\n\n1. первый\n2. второй\n\n> заметка\n\n---\n");

        self::assertStringContainsString('<h1 id="zagolovok">Заголовок</h1>', $html);
        self::assertStringContainsString('<strong>жирным</strong>', $html);
        self::assertStringContainsString('<em>курсивом</em>', $html);
        self::assertStringContainsString('<code>кодом</code>', $html);
        self::assertStringContainsString("<ul>\n<li>один</li>\n<li>два</li>\n</ul>", $html);
        self::assertStringContainsString("<ol>\n<li>первый</li>", $html);
        self::assertStringContainsString('<blockquote><p>заметка</p></blockquote>', $html);
        self::assertStringContainsString('<hr>', $html);
    }

    public function testRawHtmlIsEscapedAndUnsafeLinksAreDropped(): void
    {
        $html = Markdown::toHtml("<script>alert(1)</script> [плохая](javascript:alert(1)) [хорошая](https://example.com) [своя](/help)");

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringNotContainsString('href="javascript', $html);
        self::assertStringContainsString('href="https://example.com" rel="noopener noreferrer" target="_blank"', $html);
        self::assertStringContainsString('<a href="/help">своя</a>', $html);
    }

    public function testCodeBlockIsLiteral(): void
    {
        $html = Markdown::toHtml("```\n**не жирный** <b>\n```\n");

        self::assertStringContainsString('<pre><code>**не жирный** &lt;b&gt;', $html);
    }

    public function testFrontMatterAndHeadings(): void
    {
        [$meta, $body] = Markdown::frontMatter("---\ntitle: Договор\nversion: 2026-01-02\n---\n## Раздел\n\nТекст\n");

        self::assertSame(['title' => 'Договор', 'version' => '2026-01-02'], $meta);
        self::assertSame([['level' => 2, 'id' => 'razdel', 'text' => 'Раздел']], Markdown::headings($body));
        self::assertSame([[], 'без блока'], Markdown::frontMatter('без блока'));
    }
}
