<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Kernel\Database\Connection;
use App\Kernel\Session\Session;
use App\Support\Clock;

/**
 * Notes that a signed-in person used the service today (one row per person and day in `user_activity_days`; DAU/WAU/MAU are counted
 * from it). The session remembers the day already written, so a person costs one insert per day, not one per request.
 */
final class ActivityTracker
{
    private const SESSION_KEY = 'analytics.active_day';

    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    public function touch(Session $session, int $userId): void
    {
        $day = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');
        if ($session->get(self::SESSION_KEY) === $userId . ':' . $day) {
            return;
        }
        $this->db->execute('INSERT IGNORE INTO user_activity_days (user_id, day) VALUES (?, ?)', [$userId, $day]);
        $session->set(self::SESSION_KEY, $userId . ':' . $day);
    }
}
