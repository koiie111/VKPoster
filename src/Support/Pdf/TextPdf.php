<?php

declare(strict_types=1);

namespace App\Support\Pdf;

/**
 * A one-page A4 PDF made of text lines and rules, with the font embedded (so Cyrillic works everywhere) and a ToUnicode map (so the
 * text can be selected and searched). Deliberately small: receipts, not typesetting. No library: the file is written by hand.
 */
final class TextPdf
{
    public const WIDTH = 595;
    public const HEIGHT = 842;

    /** @var list<string> */
    private array $ops = [];

    /** @var array<int, int> glyph id => code point, for the ToUnicode map */
    private array $used = [];

    public function __construct(private readonly TrueTypeFont $font, private readonly string $title)
    {
    }

    /**
     * @param float $x points from the left edge
     * @param float $y points from the bottom edge (the baseline)
     */
    public function text(float $x, float $y, float $size, string $text): void
    {
        $hex = '';
        foreach (mb_str_split($text) as $char) {
            $code = mb_ord($char, 'UTF-8');
            $glyph = $this->font->glyph($code);
            $this->used[$glyph] = $code;
            $hex .= sprintf('%04X', $glyph);
        }
        $this->ops[] = sprintf('BT /F1 %.2F Tf %.2F %.2F Td <%s> Tj ET', $size, $x, $y, $hex);
    }

    public function rule(float $x1, float $y, float $x2, float $width = 0.5): void
    {
        $this->ops[] = sprintf('%.2F w %.2F %.2F m %.2F %.2F l S', $width, $x1, $y, $x2, $y);
    }

    /**
     * Width of a text in points at a font size, to right-align or wrap.
     */
    public function measure(string $text, float $size): float
    {
        $total = 0;
        foreach (mb_str_split($text) as $char) {
            $total += $this->font->width($this->font->glyph(mb_ord($char, 'UTF-8')));
        }

        return $total * $size / 1000;
    }

    /**
     * Break a text into lines no wider than `$width` points (at word boundaries; a single longer word is cut).
     *
     * @return list<string>
     */
    public function wrap(string $text, float $size, float $width): array
    {
        $lines = [];
        $line = '';
        foreach (self::words($text) as $word) {
            $try = $line === '' ? $word : $line . ' ' . $word;
            if ($this->measure($try, $size) <= $width) {
                $line = $try;
                continue;
            }
            if ($line !== '') {
                $lines[] = $line;
            }
            while ($this->measure($word, $size) > $width && mb_strlen($word) > 1) {
                $cut = mb_strlen($word) - 1;
                while ($cut > 1 && $this->measure(mb_substr($word, 0, $cut), $size) > $width) {
                    --$cut;
                }
                $lines[] = mb_substr($word, 0, $cut);
                $word = mb_substr($word, $cut);
            }
            $line = $word;
        }
        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private static function words(string $text): array
    {
        $words = preg_split('/\s+/u', trim($text));

        return $words === false ? [] : $words;
    }

    public function render(): string
    {
        $content = implode("\n", $this->ops);
        $fontFile = (string) gzcompress($this->font->data, 6);
        [$x0, $y0, $x1, $y1] = $this->font->bbox();

        $widths = '';
        foreach ($this->usedGlyphs() as $glyph) {
            $widths .= sprintf('%d [%d] ', $glyph, $this->font->width($glyph));
        }
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %d %d] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>', self::WIDTH, self::HEIGHT),
            4 => self::stream('', $content),
            5 => '<< /Type /Font /Subtype /Type0 /BaseFont /EmbeddedFont /Encoding /Identity-H /DescendantFonts [6 0 R] /ToUnicode 9 0 R >>',
            6 => sprintf('<< /Type /Font /Subtype /CIDFontType2 /BaseFont /EmbeddedFont /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor 7 0 R /CIDToGIDMap /Identity /DW 600 /W [%s] >>', $widths),
            7 => sprintf('<< /Type /FontDescriptor /FontName /EmbeddedFont /Flags 32 /FontBBox [%d %d %d %d] /ItalicAngle 0 /Ascent %d /Descent %d /CapHeight %d /StemV 80 /FontFile2 8 0 R >>', $x0, $y0, $x1, $y1, $this->font->ascent(), $this->font->descent(), (int) round($this->font->ascent() * 0.8)),
            8 => self::stream(sprintf('/Filter /FlateDecode /Length1 %d', strlen($this->font->data)), $fontFile),
            9 => self::stream('', $this->toUnicode()),
            10 => '<< /Title <' . strtoupper(bin2hex("\xFE\xFF" . mb_convert_encoding($this->title, 'UTF-16BE', 'UTF-8'))) . '> /Producer (ezposter) >>',
        ];

        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($out);
            $out .= $number . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($out);
        $out .= 'xref' . "\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $out .= sprintf("%010d 00000 n \n", $offset);
        }

        return $out . sprintf("trailer\n<< /Size %d /Root 1 0 R /Info 10 0 R >>\nstartxref\n%d\n%%%%EOF\n", count($objects) + 1, $xref);
    }

    /**
     * @return list<int>
     */
    private function usedGlyphs(): array
    {
        $glyphs = array_keys($this->used);
        sort($glyphs);

        return $glyphs;
    }

    private function toUnicode(): string
    {
        $entries = [];
        foreach ($this->usedGlyphs() as $glyph) {
            $entries[] = sprintf('<%04X> <%04X>', $glyph, $this->used[$glyph] > 0xFFFF ? 0xFFFD : $this->used[$glyph]);
        }
        $body = '';
        foreach (array_chunk($entries, 100) as $chunk) {
            $body .= count($chunk) . " beginbfchar\n" . implode("\n", $chunk) . "\nendbfchar\n";
        }

        return "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n"
            . $body . "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend";
    }

    private static function stream(string $dictionary, string $data): string
    {
        return '<< ' . $dictionary . ' /Length ' . strlen($data) . " >>\nstream\n" . $data . "\nendstream";
    }
}
