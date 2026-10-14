<?php

declare(strict_types=1);

namespace App\Domain\Automation\Recurring;

use App\Domain\Automation\LocalTime;
use DateTimeImmutable;
use DateTimeZone;

/**
 * When a recurring post goes out: every day, on chosen weekdays, every N days or once a month, at a wall-clock time of a time
 * zone, from a start date until an end date or a number of repeats. Pure value object; `next()` answers "when is the next one?".
 *
 * A monthly rule on the 31st goes out on the last day of shorter months (30th, 28th or 29th), never skipping the month.
 */
final class RecurrenceRule
{
    public const DAILY = 'daily';
    public const WEEKDAYS = 'weekdays';
    public const EVERY_N = 'every_n';
    public const MONTHLY = 'monthly';
    public const FREQUENCIES = [self::DAILY, self::WEEKDAYS, self::EVERY_N, self::MONTHLY];

    /** How far ahead `next()` looks for a day that fits (a monthly rule needs at most 31 days, an every-N rule N days). */
    private const SEARCH_DAYS = 800;

    /**
     * @param list<int> $weekdays ISO weekdays 1-7 (for `weekdays`)
     */
    public function __construct(
        public readonly string $frequency,
        public readonly int $intervalDays,
        public readonly array $weekdays,
        public readonly ?int $monthDay,
        public readonly string $time,
        public readonly string $timezone,
        public readonly string $startsOn,
        public readonly ?string $endsOn = null,
        public readonly ?int $maxCount = null,
    ) {
    }

    /**
     * Problems a person can fix, in Russian.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];
        if (!in_array($this->frequency, self::FREQUENCIES, true)) {
            $problems[] = 'Выберите, как часто повторять пост.';
        }
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $this->time) !== 1) {
            $problems[] = 'Укажите время публикации.';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->startsOn) !== 1 || !self::validDate($this->startsOn)) {
            $problems[] = 'Укажите дату первого повтора.';
        }
        if ($this->endsOn !== null && (!self::validDate($this->endsOn) || $this->endsOn < $this->startsOn)) {
            $problems[] = 'Дата окончания не может быть раньше даты начала.';
        }
        if ($this->frequency === self::EVERY_N && ($this->intervalDays < 1 || $this->intervalDays > 365)) {
            $problems[] = 'Интервал повтора — от 1 до 365 дней.';
        }
        if ($this->frequency === self::WEEKDAYS && $this->weekdays === []) {
            $problems[] = 'Выберите хотя бы один день недели.';
        }
        if ($this->frequency === self::MONTHLY && ($this->monthDay === null || $this->monthDay < 1 || $this->monthDay > 31)) {
            $problems[] = 'Выберите число месяца от 1 до 31.';
        }
        if ($this->maxCount !== null && $this->maxCount < 1) {
            $problems[] = 'Число повторов должно быть больше нуля.';
        }

        return $problems;
    }

    /**
     * The first occurrence strictly after `$after`, or null when the rule is over (end date passed or all repeats done).
     *
     * @param int $done occurrences already created
     */
    public function next(DateTimeImmutable $after, int $done = 0): ?DateTimeImmutable
    {
        if ($this->problems() !== [] || ($this->maxCount !== null && $done >= $this->maxCount)) {
            return null;
        }
        $zone = new DateTimeZone($this->timezone);
        $day = $after->setTimezone($zone)->setTime(0, 0)->modify('-1 day');
        $start = new DateTimeImmutable($this->startsOn . ' 00:00:00', $zone);
        if ($day < $start) {
            $day = $start;
        }
        for ($i = 0; $i < self::SEARCH_DAYS; ++$i, $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            if ($this->endsOn !== null && $date > $this->endsOn) {
                return null;
            }
            if (!$this->fitsDay($day, $start)) {
                continue;
            }
            $at = LocalTime::toUtc($date, $this->time, $this->timezone, true);
            if ($at !== null && $at > $after) {
                return $at;
            }
        }

        return null;
    }

    private function fitsDay(DateTimeImmutable $day, DateTimeImmutable $start): bool
    {
        return match ($this->frequency) {
            self::DAILY => true,
            self::WEEKDAYS => in_array((int) $day->format('N'), $this->weekdays, true),
            self::EVERY_N => $this->intervalDays > 0 && ($start->diff($day)->days) % $this->intervalDays === 0,
            self::MONTHLY => (int) $day->format('j') === min((int) $this->monthDay, (int) $day->format('t')),
            default => false,
        };
    }

    private static function validDate(string $date): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) !== 1) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
