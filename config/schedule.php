<?php

declare(strict_types=1);

use App\Domain\Admin\DailyReport;
use App\Domain\Analytics\MetricsAggregator;
use App\Domain\Auth\AuthMaintenance;
use App\Domain\Billing\RenewalService;
use App\Domain\Billing\WebhookEvents;
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
    $schedule->call('outgoing-webhooks', '* * * * *', [\App\Domain\Webhook\WebhookDispatcher::class, 'tick']);
    $schedule->call('api-webhooks-prune', '13 4 * * *', [\App\Domain\Webhook\WebhookDispatcher::class, 'prune']);
    $schedule->call('post-statistics', '* * * * *', [\App\Domain\Analytics\StatsCollector::class, 'tick']);
    $schedule->call('post-statistics-retention', '43 3 * * *', [\App\Domain\Analytics\StatsCollector::class, 'prune']);
    $schedule->call('content-maintenance', '37 * * * *', [\App\Domain\Content\Operations\ContentMaintenance::class, 'tick']);
    $schedule->call('content-automation', '* * * * *', [\App\Domain\Content\Automation\Automation::class, 'tick']);
    $schedule->call('auth-prune', '17 3 * * *', [AuthMaintenance::class, 'prune']);
    // Hourly, so the checks of many channels are spread out; a channel is rechecked once its last check is older than 6 hours.
    $schedule->call('notifications-prune', '29 3 * * *', [Notifier::class, 'prune']);
    $schedule->call('telegram-links-prune', '31 3 * * *', [TelegramLinks::class, 'prune']);
    // Every minute: jobs for publications that fall due within 90 seconds, lost-worker clean-up, deletion timers.
    $schedule->call('publish-due', '* * * * *', [PublicationScheduler::class, 'tick']);
    // Every minute: recurring posts that fall due within a day are copied, evergreen pools refill their queues, queues place their posts
    // into slots, armed triggers fire, RSS feeds that are due are queued for reading.
    $schedule->call('recurring-posts', '* * * * *', [\App\Domain\Automation\Recurring\RecurringService::class, 'tick']);
    $schedule->call('evergreen-pools', '* * * * *', [\App\Domain\Automation\Queue\EvergreenService::class, 'tick']);
    $schedule->call('queue-slots', '* * * * *', [\App\Domain\Automation\Queue\QueueService::class, 'tick']);
    $schedule->call('triggers-fire', '* * * * *', [\App\Domain\Automation\Trigger\TriggerEngine::class, 'tick']);
    $schedule->call('rss-feeds', '* * * * *', [\App\Domain\Automation\Rss\RssPoller::class, 'tick']);
    $schedule->call('crosspost-prune', '53 3 * * *', [\App\Domain\Automation\Crosspost\CrosspostSystem::class, 'prune']);
    $schedule->call('import-prune', '57 3 * * *', [\App\Domain\Automation\Import\ImportService::class, 'prune']);
    $schedule->call('channels-health', '23 * * * *', [ChannelHealthService::class, 'enqueueDue']);
    // Hourly: trial reminders and endings, renewals and their retries, payments that stayed open, unpaid invoices. Each step is safe to repeat.
    $schedule->call('billing-tick', '41 * * * *', [RenewalService::class, 'tick']);
    // Hourly: the business numbers of the last three days are counted again from the base tables (safe to repeat; see MetricsAggregator).
    $schedule->call('metrics-aggregate', '12 * * * *', [MetricsAggregator::class, 'recent']);
    // Hourly: sends the owner's daily report when it is the chosen hour and today's has not gone out yet.
    $schedule->call('daily-report', '5 * * * *', [DailyReport::class, 'tick']);
    $schedule->call('short-link-clicks-prune', '51 3 * * *', [\App\Domain\Link\ShortLinks::class, 'prune']);
    $schedule->call('webhook-events-prune', '47 3 * * *', [WebhookEvents::class, 'prune']);
};
