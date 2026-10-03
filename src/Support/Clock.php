<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;

/**
 * Source of the current time. All code reads time through this interface (never `new DateTime()`),
 * so tests can freeze it with `FakeClock`. Returned values are always in UTC.
 */
interface Clock
{
    public function now(): DateTimeImmutable;
}
