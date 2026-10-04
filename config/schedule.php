<?php

declare(strict_types=1);

use App\Domain\Auth\AuthMaintenance;
use App\Domain\Channel\ChannelHealthService;
use App\Domain\Notification\Notifier;
use App\Domain\Notification\TelegramLinks;
use App\Domain\Post\PublicationScheduler;
use App\Kernel\Queue\Schedule;

/**
 * Periodic tasks run by `bin/console schedule:run` (cron syntax, UTC).
 * Later stages register their tasks here, e.g. `$schedule->call('publish-due', '* * * * *', ...)`.
 */
return static function (Schedule $schedule): void {
    $schedule->call('auth-prune', '17 3 * * *', [AuthMaintenance::class, 'prune']);
    // Hourly, so the checks of many channels are spread out; a channel is rechecked once its last check is older than 6 hours.
    $schedule->call('notifications-prune', '29 3 * * *', [Notifier::class, 'prune']);
    $schedule->call('telegram-links-prune', '31 3 * * *', [TelegramLinks::class, 'prune']);
    // Every minute: jobs for publications that fall due within 90 seconds, lost-worker clean-up, deletion timers.
    $schedule->call('publish-due', '* * * * *', [PublicationScheduler::class, 'tick']);
    $schedule->call('channels-health', '23 * * * *', [ChannelHealthService::class, 'enqueueDue']);
};
