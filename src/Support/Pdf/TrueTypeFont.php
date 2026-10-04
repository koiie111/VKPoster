<?php

declare(strict_types=1);

namespace App\Support\Pdf;

use RuntimeException;

/**
 * The little of a TrueType font that a PDF needs: glyph ids for Unicode code points (the `cmap` table, format 4), advance widths
 * (`hmtx`), the vertical metrics and the bounding box. The font file itself is embedded as is.
 */
final class TrueTypeFont
{
    /** @var array<string, array{offset: int, length: int}> */
    private array $tables = [];

    /** @var array<int, int> code point => glyph id, filled lazily */
    private array $glyphs = [];

    private int $unitsPerEm;
    private int $ascender;
    private int $descender;

    /** @var array{int, int, int, int} */
    private array $bbox;

    private int $hMetrics;

    /** @var array{start: list<int>, end: list<int>, delta: list<int>, rangeOffset: list<int>, rangeBase: int}|null */
    private ?array $segments = null;

    /**
     * @throws RuntimeException when the file is not a readable TrueType font
     */
    public function __construct(public readonly string $data)
    {
        if (strlen($data) < 12 || !in_array(substr($data, 0, 4), ["\x00\x01\x00\x00", 'true'], true)) {
            throw new RuntimeException('Not a TrueType font (OpenType/CFF and collections are not supported).');
        }
        $count = $this->u16(4);
        for ($i = 0; $i < $count; ++$i) {
            $record = 12 + $i * 16;
            $this->tables[substr($data, $record, 4)] = ['offset' => $this->u32($record + 8), 'length' => $this->u32($record + 12)];
        }
        foreach (['head', 'hhea', 'hmtx', 'cmap', 'maxp'] as $required) {
            if (!isset($this->tables[$required])) {
                throw new RuntimeException('The font has no ' . $required . ' table.');
            }
        }
        $head = $this->tables['head']['offset'];
        $this->unitsPerEm = max(1, $this->u16($head + 18));
        $this->bbox = [$this->s16($head + 36), $this->s16($head + 38), $this->s16($head + 40), $this->s16($head + 42)];
        $hhea = $this->tables['hhea']['offset'];
        $this->ascender = $this->s16($hhea + 4);
        $this->descender = $this->s16($hhea + 6);
        $this->hMetrics = max(1, $this->u16($hhea + 34));
    }

    public static function fromFile(string $path): self
    {
        $data = is_file($path) ? file_get_contents($path) : false;
        if ($data === false) {
            throw new RuntimeException('Font file is missing: ' . $path);
        }

        return new self($data);
    }

    /** Glyph id of a code point (0, the "missing" glyph, when the font has none). */
    public function glyph(int $codePoint): int
    {
        if (isset($this->glyphs[$codePoint])) {
            return $this->glyphs[$codePoint];
        }

        return $this->glyphs[$codePoint] = $this->lookup($codePoint);
    }

    /** Advance width of a glyph in 1/1000 em (the unit of a PDF `/W` array). */
    public function width(int $glyph): int
    {
        $index = min($glyph, $this->hMetrics - 1);
        $advance = $this->u16($this->tables['hmtx']['offset'] + $index * 4);

        return (int) round($advance * 1000 / $this->unitsPerEm);
    }

    public function ascent(): int
    {
        return (int) round($this->ascender * 1000 / $this->unitsPerEm);
    }

    public function descent(): int
    {
        return (int) round($this->descender * 1000 / $this->unitsPerEm);
    }

    /**
     * @return array{int, int, int, int} xMin, yMin, xMax, yMax in 1/1000 em
     */
    public function bbox(): array
    {
        return array_map(fn (int $v): int => (int) round($v * 1000 / $this->unitsPerEm), $this->bbox);
    }

    private function lookup(int $codePoint): int
    {
        if ($codePoint > 0xFFFF) {
            return 0;
        }
        $segments = $this->segments();
        foreach ($segments['end'] as $i => $end) {
            if ($codePoint > $end) {
                continue;
            }
            if ($codePoint < $segments['start'][$i]) {
                return 0;
            }
            if ($segments['rangeOffset'][$i] === 0) {
                return ($codePoint + $segments['delta'][$i]) & 0xFFFF;
            }
            $address = $segments['rangeBase'] + $i * 2 + $segments['rangeOffset'][$i] + ($codePoint - $segments['start'][$i]) * 2;
            $glyph = $this->u16($address);

            return $glyph === 0 ? 0 : ($glyph + $segments['delta'][$i]) & 0xFFFF;
        }

        return 0;
    }

    /**
     * @return array{start: list<int>, end: list<int>, delta: list<int>, rangeOffset: list<int>, rangeBase: int}
     */
    private function segments(): array
    {
        if ($this->segments !== null) {
            return $this->segments;
        }
        $cmap = $this->tables['cmap']['offset'];
        $subtable = null;
        for ($i = 0, $n = $this->u16($cmap + 2); $i < $n; ++$i) {
            $record = $cmap + 4 + $i * 8;
            $platform = $this->u16($record);
            $encoding = $this->u16($record + 2);
            if (($platform === 3 && $encoding === 1) || ($platform === 0 && $encoding !== 5)) {
                $candidate = $cmap + $this->u32($record + 4);
                if ($this->u16($candidate) === 4) {
                    $subtable = $candidate;
                    break;
                }
            }
        }
        if ($subtable === null) {
            throw new RuntimeException('The font has no Unicode cmap of format 4.');
        }
        $count = intdiv($this->u16($subtable + 6), 2);
        $end = $start = $delta = $range = [];
        $endBase = $subtable + 14;
        $startBase = $endBase + $count * 2 + 2;
        $deltaBase = $startBase + $count * 2;
        $rangeBase = $deltaBase + $count * 2;
        for ($i = 0; $i < $count; ++$i) {
            $end[] = $this->u16($endBase + $i * 2);
            $start[] = $this->u16($startBase + $i * 2);
            $delta[] = $this->s16($deltaBase + $i * 2);
            $range[] = $this->u16($rangeBase + $i * 2);
        }

        return $this->segments = ['start' => $start, 'end' => $end, 'delta' => $delta, 'rangeOffset' => $range, 'rangeBase' => $rangeBase];
    }

    private function u16(int $offset): int
    {
        $value = unpack('n', $this->data, $offset);

        return is_array($value) ? (int) $value[1] : 0;
    }

    private function s16(int $offset): int
    {
        $value = $this->u16($offset);

        return $value >= 0x8000 ? $value - 0x10000 : $value;
    }

    private function u32(int $offset): int
    {
        $value = unpack('N', $this->data, $offset);

        return is_array($value) ? (int) $value[1] : 0;
    }
}
