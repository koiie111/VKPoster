<?php

declare(strict_types=1);

namespace App\Tests\Unit\Automation;

use App\Domain\Automation\LocalTime;
use App\Domain\Automation\Queue\SlotCalculator;
use App\Domain\Automation\Recurring\RecurrenceRule;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SlotCalculator::class)]
#[CoversClass(RecurrenceRule::class)]
#[CoversClass(LocalTime::class)]
final class SchedulingRulesTest extends TestCase
{
    private function utc(string $at): DateTimeImmutable
    {
        return new DateTimeImmutable($at, new DateTimeZone('UTC'));
    }

    /**
     * @param list<DateTimeImmutable> $times
     * @return list<string>
     */
    private function local(array $times, string $zone): array
    {
        return array_map(static fn (DateTimeImmutable $t): string => $t->setTimezone(new DateTimeZone($zone))->format('D Y-m-d H:i'), $times);
    }

    public function testSlotsAreTheNextFreeWallClockTimesOfTheZone(): void
    {
        $slots = [['weekday' => 1, 'time' => '10:00'], ['weekday' => 1, 'time' => '19:00'], ['weekday' => 3, 'time' => '10:00']];
        // Monday 2026-10-12 12:00 Moscow (09:00 UTC): the 10:00 slot of that day has passed.
        $found = SlotCalculator::next($slots, 'Europe/Moscow', $this->utc('2026-10-12 09:00:00'), 4);
        self::assertSame(['Mon 2026-10-12 19:00', 'Wed 2026-10-14 10:00', 'Mon 2026-10-19 10:00', 'Mon 2026-10-19 19:00'], $this->local($found, 'Europe/Moscow'));
    }

    public function testTakenSlotsAreSkippedAndAnEmptyScheduleGivesNothing(): void
    {
        $slots = [['weekday' => 2, 'time' => '09:00']];
        $first = SlotCalculator::next($slots, 'Europe/Moscow', $this->utc('2026-10-12 00:00:00'), 1)[0];
        $second = SlotCalculator::next($slots, 'Europe/Moscow', $this->utc('2026-10-12 00:00:00'), 1, [$first->getTimestamp() => true])[0];
        self::assertSame(7 * 86400, $second->getTimestamp() - $first->getTimestamp());
        self::assertSame([], SlotCalculator::next([], 'Europe/Moscow', $this->utc('2026-10-12 00:00:00'), 3));
        self::assertSame([], SlotCalculator::next($slots, 'Not/AZone', $this->utc('2026-10-12 00:00:00'), 3));
    }

    public function testSlotTimesFollowDaylightSavingAndSkipTheMissingHour(): void
    {
        // Berlin: clocks go forward on 2026-03-29, 02:30 does not exist that night.
        $slots = [['weekday' => 7, 'time' => '02:30'], ['weekday' => 7, 'time' => '10:00']];
        $found = SlotCalculator::next($slots, 'Europe/Berlin', $this->utc('2026-03-28 12:00:00'), 3);
        self::assertSame(['Sun 2026-03-29 10:00', 'Sun 2026-04-05 02:30', 'Sun 2026-04-05 10:00'], $this->local($found, 'Europe/Berlin'));
        // 10:00 on the 29th is already summer time (UTC+2), on the 28th it would be UTC+1: the UTC hour moved, the wall clock did not.
        self::assertSame('08:00', $found[0]->format('H:i'));
    }

    public function testSlotsAcrossTimeZonesStartFromTheLocalDay(): void
    {
        // 23:30 UTC on Sunday is already Monday 09:30 in Vladivostok.
        $slots = [['weekday' => 1, 'time' => '10:00']];
        $found = SlotCalculator::next($slots, 'Asia/Vladivostok', $this->utc('2026-10-11 23:30:00'), 1);
        self::assertSame(['Mon 2026-10-12 10:00'], $this->local($found, 'Asia/Vladivostok'));
    }

    public function testMonthlyRuleOnThe31stFallsBackToTheLastDayOfShortMonths(): void
    {
        $rule = new RecurrenceRule(RecurrenceRule::MONTHLY, 1, [], 31, '09:00', 'Europe/Moscow', '2026-01-01');
        $at = $this->utc('2026-01-01 00:00:00');
        $days = [];
        for ($i = 0; $i < 6; ++$i) {
            $at = $rule->next($at, $i) ?? self::fail('the rule must continue');
            $days[] = $at->setTimezone(new DateTimeZone('Europe/Moscow'))->format('m-d H:i');
        }
        self::assertSame(['01-31 09:00', '02-28 09:00', '03-31 09:00', '04-30 09:00', '05-31 09:00', '06-30 09:00'], $days);
        // A leap year has a 29th of February.
        $leap = new RecurrenceRule(RecurrenceRule::MONTHLY, 1, [], 30, '09:00', 'UTC', '2028-02-01');
        self::assertSame('2028-02-29', $leap->next($this->utc('2028-02-01 00:00:00'))?->format('Y-m-d'));
    }

    public function testOtherFrequenciesAndTheirEnds(): void
    {
        $zone = 'Europe/Moscow';
        $daily = new RecurrenceRule(RecurrenceRule::DAILY, 1, [], null, '10:00', $zone, '2026-10-12', '2026-10-13');
        $first = $daily->next($this->utc('2026-10-12 00:00:00'));
        self::assertSame('2026-10-12 07:00', $first?->format('Y-m-d H:i'));
        $second = $daily->next($first ?? self::fail());
        self::assertSame('2026-10-13 07:00', $second?->format('Y-m-d H:i'));
        self::assertNull($daily->next($second ?? self::fail()), 'the end date has passed');

        $limited = new RecurrenceRule(RecurrenceRule::DAILY, 1, [], null, '10:00', $zone, '2026-10-12', null, 2);
        self::assertNull($limited->next($this->utc('2026-10-12 00:00:00'), 2), 'all repeats are done');

        $weekdays = new RecurrenceRule(RecurrenceRule::WEEKDAYS, 1, [2, 4], null, '08:00', $zone, '2026-10-12');
        self::assertSame('2026-10-13', $weekdays->next($this->utc('2026-10-12 12:00:00'))?->format('Y-m-d'));

        $every3 = new RecurrenceRule(RecurrenceRule::EVERY_N, 3, [], null, '08:00', $zone, '2026-10-12');
        $a = $every3->next($this->utc('2026-10-12 12:00:00'));
        self::assertSame('2026-10-15', $a?->format('Y-m-d'));
        self::assertSame('2026-10-18', $every3->next($a ?? self::fail())?->format('Y-m-d'));
    }

    public function testRulesAreValidated(): void
    {
        $bad = new RecurrenceRule('yearly', 0, [], null, '25:00', 'UTC', 'soon', '2020-01-01', 0);
        self::assertGreaterThanOrEqual(3, count($bad->problems()));
        self::assertNull($bad->next($this->utc('2026-01-01 00:00:00')));
        self::assertNotSame([], (new RecurrenceRule(RecurrenceRule::WEEKDAYS, 1, [], null, '10:00', 'UTC', '2026-01-01'))->problems());
        self::assertNotSame([], (new RecurrenceRule(RecurrenceRule::MONTHLY, 1, [], 32, '10:00', 'UTC', '2026-01-01'))->problems());
        self::assertSame([], (new RecurrenceRule(RecurrenceRule::EVERY_N, 5, [], null, '10:00', 'UTC', '2026-01-01'))->problems());
    }

    public function testRecurringTimeKeepsTheWallClockAcrossDaylightSaving(): void
    {
        $rule = new RecurrenceRule(RecurrenceRule::DAILY, 1, [], null, '02:30', 'Europe/Berlin', '2026-03-28');
        $saturday = $rule->next($this->utc('2026-03-28 00:00:00')) ?? self::fail();
        $sunday = $rule->next($saturday) ?? self::fail();
        // 02:30 does not exist on the 29th: the post is moved forward, not lost.
        self::assertSame('2026-03-29 03:30', $sunday->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d H:i'));
        self::assertNull(LocalTime::toUtc('2026-03-29', '02:30', 'Europe/Berlin', false));
        self::assertNull(LocalTime::toUtc('2026-13-40', '10:00', 'Europe/Berlin', true));
        self::assertNull(LocalTime::toUtc('2026-03-29', '10:00', 'Mars/Base', true));
        // The doubled autumn hour is taken once, as its first (summer) occurrence.
        self::assertSame('2026-10-25 00:30', LocalTime::toUtc('2026-10-25', '02:30', 'Europe/Berlin', true)?->format('Y-m-d H:i'));
    }
}
