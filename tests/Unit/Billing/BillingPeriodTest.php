<?php

declare(strict_types=1);

namespace App\Tests\Unit\Billing;

use App\Domain\Billing\BillingPeriod;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(BillingPeriod::class)]
final class BillingPeriodTest extends TestCase
{
    /**
     * @return iterable<string, array{string, BillingPeriod, string}>
     */
    public static function cases(): iterable
    {
        yield 'plain month' => ['2026-10-04 12:00:00', BillingPeriod::Month, '2026-11-04 12:00:00'];
        yield '31 January clamps to the end of February' => ['2026-01-31 08:30:00', BillingPeriod::Month, '2026-02-28 08:30:00'];
        yield 'leap February' => ['2028-01-31 08:30:00', BillingPeriod::Month, '2028-02-29 08:30:00'];
        yield '30 April + 1 month' => ['2026-04-30 00:00:00', BillingPeriod::Month, '2026-05-30 00:00:00'];
        yield 'December rolls the year' => ['2026-12-15 10:00:00', BillingPeriod::Month, '2027-01-15 10:00:00'];
        yield 'year' => ['2026-10-04 12:00:00', BillingPeriod::Year, '2027-10-04 12:00:00'];
        yield '29 February + 1 year' => ['2028-02-29 12:00:00', BillingPeriod::Year, '2029-02-28 12:00:00'];
    }

    #[DataProvider('cases')]
    public function testAddingAPeriodKeepsTheDayWhereItExists(string $from, BillingPeriod $period, string $expected): void
    {
        $result = $period->addTo(new DateTimeImmutable($from, new DateTimeZone('UTC')));

        self::assertSame($expected, $result->format('Y-m-d H:i:s'));
    }

    public function testLabelsAndMonths(): void
    {
        self::assertSame(1, BillingPeriod::Month->months());
        self::assertSame(12, BillingPeriod::Year->months());
        self::assertSame('в год', BillingPeriod::Year->perLabel());
        self::assertSame('за месяц', BillingPeriod::Month->forLabel());
    }
}
