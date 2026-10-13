<?php

declare(strict_types=1);

namespace App\Domain\Automation\Queue;

use App\Domain\Automation\LocalTime;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Finds the next free slots of a weekly schedule ("Mon-Fri 10:00, 15:00"). Pure: the slots, the time zone, the moment to start
 * from and the moments that are already taken go in, UTC instants come out.
 *
 * Slots are wall-clock times of the zone, so "10:00" stays 10:00 across daylight saving changes; a time that does not exist on
 * the night the clocks go forward is skipped for that day.
 */
final class SlotCalculator
{
    public const MAX_DAYS = 400;

    /**
     * @param list<array{weekday: int, time: string}> $slots weekday 1 (Monday) to 7 (Sunday), time "HH:MM"
     * @param array<int, true> $taken UTC timestamps (seconds) that are not free
     * @return list<DateTimeImmutable> up to `$count` free slots after `$after`, in order
     */
    public static function next(array $slots, string $timezone, DateTimeImmutable $after, int $count, array $taken = []): array
    {
        if ($slots === [] || $count < 1) {
            return [];
        }
        $byDay = [];
        foreach ($slots as $slot) {
            $byDay[$slot['weekday']][] = $slot['time'];
        }
        foreach ($byDay as $day => $times) {
            sort($times);
            $byDay[$day] = array_values(array_unique($times));
        }
        try {
            $zone = new DateTimeZone($timezone);
        } catch (\Throwable) {
            return [];
        }
        $day = $after->setTimezone($zone)->setTime(0, 0);
        $found = [];
        for ($i = 0; $i < self::MAX_DAYS && count($found) < $count; ++$i, $day = $day->modify('+1 day')) {
            foreach ($byDay[(int) $day->format('N')] ?? [] as $time) {
                $at = LocalTime::toUtc($day->format('Y-m-d'), $time, $timezone, false);
                if ($at === null || $at <= $after || isset($taken[$at->getTimestamp()])) {
                    continue;
                }
                $found[] = $at;
                if (count($found) >= $count) {
                    break;
                }
            }
        }

        return $found;
    }
}
