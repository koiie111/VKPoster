<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Formatting of money kept as integer minor units (kopecks, cents). No floats: arithmetic stays in integers, and the decimal string
 * for a payment provider is produced from the integer.
 */
final class Money
{
    private const SYMBOLS = ['RUB' => '₽', 'USD' => '$', 'EUR' => '€'];

    /** "990 ₽", "1 249,50 ₽": thin groups of thousands, kopecks only when there are some. */
    public static function format(int $minor, string $currency = 'RUB'): string
    {
        $whole = intdiv(abs($minor), 100);
        $fraction = abs($minor) % 100;
        $text = number_format($whole, 0, '', "\u{00A0}") . ($fraction > 0 ? sprintf(',%02d', $fraction) : '');

        return ($minor < 0 ? '−' : '') . $text . "\u{00A0}" . (self::SYMBOLS[$currency] ?? $currency);
    }

    /** "990.00", the plain decimal string payment APIs expect. */
    public static function decimal(int $minor): string
    {
        return sprintf('%s%d.%02d', $minor < 0 ? '-' : '', intdiv(abs($minor), 100), abs($minor) % 100);
    }

    /**
     * Parse a provider's decimal amount ("990.00", "990") to minor units, or null when it is not a plain amount.
     */
    public static function fromDecimal(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value * 100;
        }
        if (!is_string($value) || preg_match('/^(\d{1,12})(?:\.(\d{1,2}))?$/', $value, $m) !== 1) {
            return null;
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    }
}
