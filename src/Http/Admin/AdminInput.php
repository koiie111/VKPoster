<?php

declare(strict_types=1);

namespace App\Http\Admin;

use App\Kernel\Http\Request;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Reads the usual filter inputs of admin lists (text, page number, `Y-m-d` dates in the staff member's time zone) and builds the query
 * string that keeps filters while paging or sorting.
 */
final class AdminInput
{
    public static function text(Request $request, string $key, int $max = 200): string
    {
        $value = $request->input($key);

        return is_string($value) ? mb_substr(trim($value), 0, $max) : '';
    }

    /**
     * A value that must be one of the allowed ones; anything else becomes the default.
     *
     * @param list<string> $allowed
     */
    public static function choice(Request $request, string $key, array $allowed, string $default = ''): string
    {
        $value = $request->input($key);

        return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
    }

    public static function page(Request $request): int
    {
        $page = $request->input('page');

        return is_string($page) && ctype_digit($page) && strlen($page) < 7 ? max(1, (int) $page) : 1;
    }

    /**
     * Midnight (in `$timezone`) of a `Y-m-d` date, as UTC; null for anything else. With `$endExclusive` the start of the next day.
     */
    public static function date(Request $request, string $key, string $timezone, bool $endExclusive = false): ?DateTimeImmutable
    {
        $value = $request->input($key);
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }
        try {
            $zone = new DateTimeZone($timezone);
            $day = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone);
        } catch (\Exception) {
            return null;
        }
        if ($day === false || $day->format('Y-m-d') !== $value) {
            return null;
        }

        return ($endExclusive ? $day->modify('+1 day') : $day)->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * `?a=1&b=2` of the non-empty parameters, or an empty string.
     *
     * @param array<string, scalar|null> $params
     */
    public static function query(array $params): string
    {
        $params = array_filter($params, static fn (mixed $v): bool => $v !== null && $v !== '');

        return $params === [] ? '' : '?' . http_build_query($params);
    }
}
