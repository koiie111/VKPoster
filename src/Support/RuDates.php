<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;

/**
 * Russian names of months and weekdays for the interface (PHP's own formatting is English, and `intl` would be one more moving part
 * for a handful of words).
 */
final class RuDates
{
    private const MONTHS = ['', 'январь', 'февраль', 'март', 'апрель', 'май', 'июнь', 'июль', 'август', 'сентябрь', 'октябрь', 'ноябрь', 'декабрь'];
    private const MONTHS_OF = ['', 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
    private const WEEKDAYS_SHORT = ['', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
    private const WEEKDAYS = ['', 'понедельник', 'вторник', 'среда', 'четверг', 'пятница', 'суббота', 'воскресенье'];

    /** "Октябрь 2026" */
    public static function monthYear(DateTimeImmutable $date): string
    {
        return ucfirst(self::MONTHS[(int) $date->format('n')]) . ' ' . $date->format('Y');
    }

    /** "5 октября" */
    public static function dayMonth(DateTimeImmutable $date): string
    {
        return (int) $date->format('j') . ' ' . self::MONTHS_OF[(int) $date->format('n')];
    }

    /** "5 октября 2026, 12:30" */
    public static function full(DateTimeImmutable $date): string
    {
        return self::dayMonth($date) . ' ' . $date->format('Y') . ', ' . $date->format('H:i');
    }

    /** "Пн" */
    public static function weekdayShort(DateTimeImmutable $date): string
    {
        return self::WEEKDAYS_SHORT[(int) $date->format('N')];
    }

    /** "понедельник, 5 октября" */
    public static function weekdayDay(DateTimeImmutable $date): string
    {
        return self::WEEKDAYS[(int) $date->format('N')] . ', ' . self::dayMonth($date);
    }
}
