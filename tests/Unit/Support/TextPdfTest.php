<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Support\Pdf\TextPdf;
use App\Support\Pdf\TrueTypeFont;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(TextPdf::class)]
#[CoversClass(TrueTypeFont::class)]
final class TextPdfTest extends TestCase
{
    private const FONT = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';

    protected function setUp(): void
    {
        if (!is_file(self::FONT)) {
            self::markTestSkipped('DejaVu Sans is not installed.');
        }
    }

    public function testTheFontMapsCyrillicAndTheRubleSign(): void
    {
        $font = TrueTypeFont::fromFile(self::FONT);

        foreach (['А', 'я', 'ё', '₽', '«', '»', '—', '•', '5', 'Z'] as $char) {
            self::assertGreaterThan(0, $font->glyph(mb_ord($char, 'UTF-8')), $char . ' must have a glyph');
        }
        self::assertSame(0, $font->glyph(0x1F600), 'an emoji is not in the font: the "missing" glyph');
        self::assertGreaterThan(0, $font->width($font->glyph(mb_ord('Ш', 'UTF-8'))));
        self::assertGreaterThan($font->width($font->glyph(mb_ord('i', 'UTF-8'))), $font->width($font->glyph(mb_ord('W', 'UTF-8'))));
    }

    public function testTheFileIsAWellFormedPdfWithAnExactCrossReferenceTable(): void
    {
        $pdf = new TextPdf(TrueTypeFont::fromFile(self::FONT), 'Квитанция EZ-000001');
        $pdf->text(56, 770, 20, 'Квитанция об оплате');
        $pdf->rule(56, 760, 539);

        $out = $pdf->render();

        self::assertStringStartsWith('%PDF-1.4', $out);
        self::assertStringEndsWith("%%EOF\n", $out);
        $startxref = (int) self::capturePdf('/startxref\n(\d+)\n%%EOF/', $out);
        self::assertSame('xref', substr($out, $startxref, 4), 'startxref points at the table');
        // Every object offset in the table points at "N 0 obj".
        preg_match_all('/^(\d{10}) 00000 n $/m', $out, $offsets);
        foreach ($offsets[1] as $number => $offset) {
            self::assertStringStartsWith(($number + 1) . ' 0 obj', substr($out, (int) $offset, 12));
        }
        self::assertCount(10, $offsets[1]);
        self::assertStringContainsString('/FontFile2 8 0 R', $out);
        self::assertStringContainsString('/ToUnicode 9 0 R', $out);
    }

    private static function capturePdf(string $pattern, string $subject): string
    {
        return preg_match($pattern, $subject, $m) === 1 ? $m[1] : self::fail('No match for ' . $pattern);
    }

    public function testTheTextCanBeFoundThroughTheToUnicodeMap(): void
    {
        $font = TrueTypeFont::fromFile(self::FONT);
        $pdf = new TextPdf($font, 'x');
        $pdf->text(10, 10, 10, 'Да');

        $out = $pdf->render();

        self::assertStringContainsString(sprintf('<%04X> <0414>', $font->glyph(0x0414)), $out, 'Д');
        self::assertStringContainsString(sprintf('<%04X> <0430>', $font->glyph(0x0430)), $out, 'а');
        self::assertStringContainsString(sprintf('<%04X%04X> Tj', $font->glyph(0x0414), $font->glyph(0x0430)), $out);
    }

    public function testWrappingKeepsLinesInsideTheWidthAndCutsLongWords(): void
    {
        $pdf = new TextPdf(TrueTypeFont::fromFile(self::FONT), 'x');

        $lines = $pdf->wrap('Это квитанция об оплате сервиса и ещё немного слов для переноса', 11, 150);

        self::assertGreaterThan(2, count($lines));
        foreach ($lines as $line) {
            self::assertLessThanOrEqual(150.0, $pdf->measure($line, 11));
        }
        $long = $pdf->wrap(str_repeat('Ш', 80), 11, 100);
        self::assertGreaterThan(1, count($long));
        self::assertSame(str_repeat('Ш', 80), implode('', $long), 'nothing is lost');
        self::assertSame([], $pdf->wrap('   ', 11, 100));
    }

    public function testNonFontsAreRefused(): void
    {
        $this->expectException(RuntimeException::class);
        new TrueTypeFont('this is not a font at all, just text');
    }

    public function testAMissingFontFileIsReported(): void
    {
        $this->expectException(RuntimeException::class);
        TrueTypeFont::fromFile('/nonexistent/font.ttf');
    }
}
