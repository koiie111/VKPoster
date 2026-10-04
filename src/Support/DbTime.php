<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Conversion between PHP dates and MySQL `DATETIME(6)` strings. The database stores UTC only
 * (the connection time zone is UTC), so every value is normalised to UTC on the way in.
 */
final class DbTime
{
    public const FORMAT = 'Y-m-d H:i:s.u';

    public static function format(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format(self::FORMAT);
    }

    public static function parse(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}
