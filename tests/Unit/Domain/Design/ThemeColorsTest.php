<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Design;

use App\Domain\Design\ThemeColors;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The standard colours kept in PHP must be the ones in the stylesheet, or "reset to standard" would not mean what it says.
 */
#[CoversClass(ThemeColors::class)]
final class ThemeColorsTest extends TestCase
{
    /**
     * @return array<string, string> token => triplet declared in the first rule that starts with `$selector`
     */
    private function declared(string $css, string $selector): array
    {
        $start = strpos($css, $selector . ' {');
        self::assertNotFalse($start, $selector);
        $end = strpos($css, "\n  }", $start);
        self::assertNotFalse($end);
        preg_match_all('/--([a-z-]+):\s*(\d{1,3} \d{1,3} \d{1,3})\s*;/', substr($css, $start, $end - $start), $m, PREG_SET_ORDER);
        $found = [];
        foreach ($m as $row) {
            $found[$row[1]] = $row[2];
        }

        return $found;
    }

    public function testStandardColoursEqualTheStylesheet(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 4) . '/resources/css/app.css');
        self::assertSame(ThemeColors::LIGHT, array_intersect_key($this->declared($css, ':root'), ThemeColors::LIGHT));
        self::assertSame(ThemeColors::DARK, array_intersect_key($this->declared($css, ":root[data-theme='dark']"), ThemeColors::DARK) + array_diff_key(ThemeColors::DARK, $this->declared($css, ":root[data-theme='dark']")));
        // Every token of the form has a standard value in both themes, and a label.
        self::assertSame(array_keys(ThemeColors::TOKENS), array_keys(ThemeColors::LIGHT));
        self::assertSame(array_keys(ThemeColors::TOKENS), array_keys(ThemeColors::DARK));
    }

    public function testHexConversions(): void
    {
        self::assertSame('79 70 229', ThemeColors::hexToTriplet('#4f46e5'));
        self::assertSame('255 255 255', ThemeColors::hexToTriplet(' #FFFFFF '));
        self::assertNull(ThemeColors::hexToTriplet('#fff'));
        self::assertNull(ThemeColors::hexToTriplet('red'));
        self::assertNull(ThemeColors::hexToTriplet('#12345g'));
        self::assertSame('#4f46e5', ThemeColors::tripletToHex('79 70 229'));
        self::assertTrue(ThemeColors::validTriplet('0 255 17'));
        self::assertFalse(ThemeColors::validTriplet('256 0 0'));
        self::assertFalse(ThemeColors::validTriplet('1 2'));
        self::assertFalse(ThemeColors::validTriplet('1 2 3; } body { display: none'));
    }

    public function testContrastRatio(): void
    {
        self::assertEqualsWithDelta(21.0, ThemeColors::ratio('0 0 0', '255 255 255'), 0.01);
        self::assertEqualsWithDelta(1.0, ThemeColors::ratio('10 20 30', '10 20 30'), 0.001);
        self::assertGreaterThan(4.5, ThemeColors::ratio(ThemeColors::LIGHT['fg'], ThemeColors::LIGHT['bg']));
    }
}
