<?php

declare(strict_types=1);

use App\Domain\Auth\AuthMaintenance;
use App\Kernel\Queue\Schedule;

/**
 * Periodic tasks run by `bin/console schedule:run` (cron syntax, UTC).
 * Later stages register their tasks here, e.g. `$schedule->call('publish-due', '* * * * *', ...)`.
 */
return static function (Schedule $schedule): void {
    $schedule->call('auth-prune', '17 3 * * *', [AuthMaintenance::class, 'prune']);
};
