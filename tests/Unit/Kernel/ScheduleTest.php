<?php

declare(strict_types=1);

namespace App\Tests\Unit\Kernel;

use App\Kernel\Container;
use App\Kernel\Queue\Schedule;
use App\Kernel\Queue\ScheduledTask;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Cron matching and the schedule runner.
 */
#[CoversClass(Schedule::class)]
#[CoversClass(ScheduledTask::class)]
final class ScheduleTest extends TestCase
{
    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function crons(): array
    {
        return [
            'every minute' => ['* * * * *', '2026-03-04 05:06:00', true],
            'exact minute' => ['6 5 * * *', '2026-03-04 05:06:00', true],
            'wrong minute' => ['7 5 * * *', '2026-03-04 05:06:00', false],
            'step' => ['*/15 * * * *', '2026-03-04 05:45:00', true],
            'step miss' => ['*/15 * * * *', '2026-03-04 05:46:00', false],
            'list' => ['0,30 * * * *', '2026-03-04 05:30:00', true],
            'range' => ['0 9-17 * * *', '2026-03-04 12:00:00', true],
            'range miss' => ['0 9-17 * * *', '2026-03-04 18:00:00', false],
            'weekday' => ['0 0 * * 3', '2026-03-04 00:00:00', true], // 2026-03-04 is a Wednesday
            'weekday miss' => ['0 0 * * 1', '2026-03-04 00:00:00', false],
            'month' => ['0 0 1 3 *', '2026-03-01 00:00:00', true],
        ];
    }

    #[DataProvider('crons')]
    public function testIsDue(string $cron, string $at, bool $due): void
    {
        $task = new ScheduledTask('t', $cron, static function (): void {
        });

        self::assertSame($due, $task->isDue(new DateTimeImmutable($at, new DateTimeZone('UTC'))));
    }

    public function testBadCronIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ScheduledTask('t', '* * *', static function (): void {
        });
    }

    public function testRunDueRunsOnlyDueTasksAndSurvivesFailures(): void
    {
        $ran = [];
        $schedule = new Schedule(new Container(), new NullLogger());
        $schedule->call('boom', '* * * * *', static function (): void {
            throw new RuntimeException('fail');
        });
        $schedule->call('hourly', '0 * * * *', static function () use (&$ran): void {
            $ran[] = 'hourly';
        });
        $schedule->call('minute', '* * * * *', static function () use (&$ran): void {
            $ran[] = 'minute';
        });

        $names = $schedule->runDue(new DateTimeImmutable('2026-01-01 10:30:00', new DateTimeZone('UTC')));

        self::assertSame(['minute'], $names);
        self::assertSame(['minute'], $ran);
        self::assertCount(3, $schedule->tasks());
    }
}
