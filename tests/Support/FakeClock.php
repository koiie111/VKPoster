<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\Clock;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Controllable clock for tests.
 */
final class FakeClock implements Clock
{
    private DateTimeImmutable $now;

    public function __construct(string $time = '2026-01-01 12:00:00')
    {
        $this->now = new DateTimeImmutable($time, new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify(sprintf('%+d seconds', $seconds));
    }

    public function set(string $time): void
    {
        $this->now = new DateTimeImmutable($time, new DateTimeZone('UTC'));
    }
}
