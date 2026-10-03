<?php

declare(strict_types=1);

namespace App\Kernel\Queue;

use DateTimeImmutable;

/**
 * A periodic task: a name, a 5-field cron expression (UTC) and an action.
 * Supported field syntax: `*`, `N`, `A-B`, `* /N` (without the space), and comma lists.
 */
final class ScheduledTask
{
    /**
     * @param \Closure(): void $action
     */
    public function __construct(
        public readonly string $name,
        private readonly string $cron,
        private readonly \Closure $action,
    ) {
        if (count(self::fields($cron)) !== 5) {
            throw new \InvalidArgumentException('Cron expression must have 5 fields.');
        }
    }

    public function isDue(DateTimeImmutable $at): bool
    {
        $fields = self::fields($this->cron);
        $values = [(int) $at->format('i'), (int) $at->format('G'), (int) $at->format('j'), (int) $at->format('n'), (int) $at->format('w')];
        $ranges = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 6]];
        foreach ($fields as $i => $field) {
            if (!self::fieldMatches($field, $values[$i], $ranges[$i][0], $ranges[$i][1])) {
                return false;
            }
        }

        return true;
    }

    public function run(): void
    {
        ($this->action)();
    }

    /**
     * @return list<string>
     */
    private static function fields(string $cron): array
    {
        return array_values(array_filter(explode(' ', str_replace("\t", ' ', trim($cron))), static fn (string $f): bool => $f !== ''));
    }

    private static function fieldMatches(string $field, int $value, int $min, int $max): bool
    {
        foreach (explode(',', $field) as $part) {
            $step = 1;
            if (str_contains($part, '/')) {
                [$part, $stepRaw] = explode('/', $part, 2);
                $step = max(1, (int) $stepRaw);
            }
            if ($part === '*') {
                $from = $min;
                $to = $max;
            } elseif (str_contains($part, '-')) {
                [$a, $b] = explode('-', $part, 2);
                $from = (int) $a;
                $to = (int) $b;
            } else {
                $from = (int) $part;
                $to = $step > 1 ? $max : $from;
            }
            if ($value >= $from && $value <= $to && ($value - $from) % $step === 0) {
                return true;
            }
        }

        return false;
    }
}
