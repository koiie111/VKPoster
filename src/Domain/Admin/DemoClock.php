<?php

declare(strict_types=1);

namespace App\Domain\Admin;

use App\Support\Clock;
use DateTimeImmutable;

/**
 * A clock that shows whatever moment it is told to, so demo payments can be written into the money journal at the time they "happened".
 */
final class DemoClock implements Clock
{
    public function __construct(public DateTimeImmutable $at)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->at;
    }
}
