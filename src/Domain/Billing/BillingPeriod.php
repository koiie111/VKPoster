<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use DateTimeImmutable;

/**
 * Length of one paid period. Month arithmetic keeps the day of the month where it exists and clamps to the last day otherwise
 * (31 January + 1 month = 28 February), so a subscription does not drift by a few days each year.
 */
enum BillingPeriod: string
{
    case Month = 'month';
    case Year = 'year';

    public function addTo(DateTimeImmutable $from): DateTimeImmutable
    {
        $months = $this === self::Month ? 1 : 12;
        $total = (int) $from->format('Y') * 12 + ((int) $from->format('n') - 1) + $months;
        $year = intdiv($total, 12);
        $month = $total % 12 + 1;
        $lastDay = (int) $from->setDate($year, $month, 1)->format('t');

        return $from->setDate($year, $month, min((int) $from->format('j'), $lastDay));
    }

    public function label(): string
    {
        return $this === self::Month ? 'месяц' : 'год';
    }

    /** "в месяц" / "в год", for price captions. */
    public function perLabel(): string
    {
        return $this === self::Month ? 'в месяц' : 'в год';
    }

    /** "за месяц" / "за год", for invoice descriptions. */
    public function forLabel(): string
    {
        return $this === self::Month ? 'за месяц' : 'за год';
    }

    /** How many months a price of this period is spread over (to compare a monthly and a yearly price). */
    public function months(): int
    {
        return $this === self::Month ? 1 : 12;
    }
}
