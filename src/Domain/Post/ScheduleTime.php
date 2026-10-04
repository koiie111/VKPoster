<?php

declare(strict_types=1);

namespace App\Domain\Post;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Turns "date and time on the wall clock of a time zone" into a moment in UTC, strictly: the wall-clock time must exist (clocks
 * jump forward one hour in spring, so 02:30 does not exist that night) and must be in the future. In autumn a time occurs
 * twice; the first occurrence (summer time) is taken.
 */
final class ScheduleTime
{
    /**
     * @throws PostException with a message for the person
     */
    public static function parse(string $date, string $time, string $timezone, DateTimeImmutable $now): DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || preg_match('/^\d{2}:\d{2}$/', $time) !== 1) {
            throw new PostException('Укажите дату и время публикации.');
        }
        [$year, $month, $day] = array_map('intval', explode('-', $date));
        [$hour, $minute] = array_map('intval', explode(':', $time));
        if (!checkdate($month, $day, $year) || $hour > 23 || $minute > 59) {
            throw new PostException('Такой даты или времени не существует. Проверьте, что всё указано верно.');
        }
        try {
            $zone = new DateTimeZone($timezone);
            $local = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $time, $zone);
        } catch (Throwable) {
            $local = false;
        }
        if ($local === false) {
            throw new PostException('Не удалось понять часовой пояс. Проверьте настройки пространства.');
        }
        // A wall-clock time skipped by the spring clock change is moved forward by PHP: the date or time then differs from what was typed.
        if ($local->format('Y-m-d H:i') !== $date . ' ' . $time) {
            throw new PostException('В этот день часы переводятся, и такого времени не существует. Выберите другое.');
        }
        $utc = $local->setTimezone(new DateTimeZone('UTC'));
        if ($utc <= $now) {
            throw new PostException('Это время уже прошло. Выберите время в будущем.');
        }

        return $utc;
    }

    /**
     * "Europe/Moscow, UTC+3" for hints next to a time field.
     */
    public static function label(string $timezone, DateTimeImmutable $at): string
    {
        try {
            $offset = (new DateTimeZone($timezone))->getOffset($at);
        } catch (Throwable) {
            return $timezone;
        }
        $hours = intdiv(abs($offset), 3600);
        $minutes = intdiv(abs($offset) % 3600, 60);

        return sprintf('%s, UTC%s%d%s', $timezone, $offset < 0 ? '−' : '+', $hours, $minutes > 0 ? sprintf(':%02d', $minutes) : '');
    }
}
