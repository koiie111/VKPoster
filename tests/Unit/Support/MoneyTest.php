<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Support\Money;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Money::class)]
final class MoneyTest extends TestCase
{
    public function testFormatsWholeRublesAndKopecks(): void
    {
        self::assertSame("990\u{00A0}₽", Money::format(99000));
        self::assertSame("1\u{00A0}249,50\u{00A0}₽", Money::format(124950));
        self::assertSame("29\u{00A0}900\u{00A0}₽", Money::format(2990000));
        self::assertSame("0\u{00A0}₽", Money::format(0));
        self::assertSame("−5\u{00A0}₽", Money::format(-500));
        self::assertSame("10\u{00A0}$", Money::format(1000, 'USD'));
        self::assertSame("10\u{00A0}CHF", Money::format(1000, 'CHF'), 'an unknown currency shows its code');
    }

    public function testDecimalStringForProviders(): void
    {
        self::assertSame('990.00', Money::decimal(99000));
        self::assertSame('0.05', Money::decimal(5));
        self::assertSame('1249.50', Money::decimal(124950));
        self::assertSame('-1.00', Money::decimal(-100));
    }

    public function testParsesProviderAmounts(): void
    {
        self::assertSame(99000, Money::fromDecimal('990.00'));
        self::assertSame(99000, Money::fromDecimal('990'));
        self::assertSame(50, Money::fromDecimal('0.5'));
        self::assertSame(12345, Money::fromDecimal('123.45'));
        self::assertNull(Money::fromDecimal('990.999'));
        self::assertNull(Money::fromDecimal('abc'));
        self::assertNull(Money::fromDecimal(null));
        self::assertNull(Money::fromDecimal('-1.00'));
    }
}
