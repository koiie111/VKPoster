<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use DateTimeImmutable;

/**
 * What the owner narrowed the dashboard to: a period of whole UTC days (both ends included), a plan, a network, a traffic source and the
 * currency in which money is shown (currencies are never added together).
 */
final class ReportFilters
{
    public function __construct(
        public readonly DateTimeImmutable $from,
        public readonly DateTimeImmutable $to,
        public readonly string $plan = '',
        public readonly string $platform = '',
        public readonly string $source = '',
        public readonly string $currency = 'RUB',
    ) {
    }

    /** Number of days in the period. */
    public function days(): int
    {
        return $this->from->diff($this->to)->days + 1;
    }

    /**
     * The period of the same length that ends the day before this one starts (what the numbers are compared with).
     */
    public function previous(): self
    {
        $days = $this->days();

        return new self($this->from->modify('-' . $days . ' days'), $this->from->modify('-1 day'), $this->plan, $this->platform, $this->source, $this->currency);
    }
}
