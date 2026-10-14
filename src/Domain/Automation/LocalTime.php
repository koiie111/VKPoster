<?php

declare(strict_types=1);

namespace App\Domain\Automation;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Wall-clock date and time of a time zone to a moment in UTC, for schedules that repeat (slots, recurring posts). Unlike
 * `ScheduleTime` it does not look at "now" and does not throw: a time that does not exist (clocks jump forward in spring) is
 * either skipped (`null`) or moved forward as PHP does, and in autumn the first occurrence of the doubled hour is taken.
 */
final class LocalTime
{
    /**
     * @param bool $shiftForward true = a skipped wall-clock time becomes the next valid one; false = it yields null
     */
    public static function toUtc(string $date, string $time, string $timezone, bool $shiftForward): ?DateTimeImmutable
    {
        try {
            $zone = new DateTimeZone($timezone);
            $local = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $time, $zone);
        } catch (Throwable) {
            return null;
        }
        // A date or time that overflows (2026-13-40, 25:00) is not a skipped hour, it is wrong.
        if ($local === false || $local->format('Y-m-d') !== $date) {
            return null;
        }
        $exists = $local->format('Y-m-d H:i') === $date . ' ' . $time;
        if (!$exists && !$shiftForward) {
            return null;
        }
        // The autumn hour that happens twice: PHP picks the second occurrence, the first (summer time) is the one people mean.
        if ($exists) {
            $earlier = $local->setTimestamp($local->getTimestamp() - 3600);
            if ($earlier->format('Y-m-d H:i') === $date . ' ' . $time) {
                $local = $earlier;
            }
        }

        return $local->setTimezone(new DateTimeZone('UTC'));
    }
}
