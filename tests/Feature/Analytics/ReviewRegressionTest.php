<?php

declare(strict_types=1);

namespace App\Tests\Feature\Analytics;

use App\Domain\Analytics\{StatsCollector,StatsJob,BestTimeHints,PostAnalytics,PartialMetricsFailure};
use App\Domain\Approval\GuestLinks;
use App\Domain\Workspace\{Role,ChannelAccessRepository};
use App\Integrations\Social\Contracts\{Credential,Platform};
use App\Integrations\Social\Instagram\InstagramStatsFetcher;
use App\Integrations\Social\Fake\FakeStatsFetcher;
use App\Kernel\Queue\{Queue,Worker};
use App\Kernel\Security\RateLimiter;
use App\Support\DbTime;
use App\Tests\Support\BillingTestCase;

final class ReviewRegressionTest extends BillingTestCase
{
    /** @return array{\App\Domain\Workspace\WorkspaceContext,\App\Domain\Channel\Channel,\App\Domain\Post\Post,int} */
    private function published(): array
    {
        [$owner,$workspace] = $this->ownerWithWorkspace();
        $this->givePlan($workspace, 'pro');
        $ctx = $this->contextFor($workspace, $owner);
        $channel = $this->fakeChannel($workspace, $owner);
        [$post,$pubs] = $this->scheduled($ctx, [$channel], '+1 minute');
        $this->clock->advance(61);
        $this->drain();
        $this->actAs($owner);
        return [$ctx,$channel,$post,$pubs[0]->id];
    }

    /**
     * @return list<string>
     * @phpstan-impure
     */
    private function keys(int $pub): array
    {
        return array_column($this->db->select('SELECT task_key FROM analytics_tasks WHERE publication_id=? ORDER BY id', [$pub]), 'task_key');
    }

    /** @phpstan-impure */
    private function snapshotCount(): int
    {
        return $this->db->table('channel_stats_snapshots')->count();
    }

    public function testNormalMilestonesAndOutageSkipMissedIntervals(): void
    {
        [,,,$pub] = $this->published();
        $collector = $this->app->container()->get(StatsCollector::class);
        $this->clock->advance(3600);
        $collector->tick();
        self::assertSame(['post:'.$pub.':1'], $this->keys($pub));
        $this->clock->advance(5 * 3600);
        $collector->tick();
        self::assertSame(['post:'.$pub.':1','post:'.$pub.':6'], $this->keys($pub));
        $this->clock->advance(9 * 86400); // 24/72/168h missed, only current 168h is meaningful.
        $collector->tick();
        $collector->tick();
        self::assertSame(['post:'.$pub.':1','post:'.$pub.':6','post:'.$pub.':168'], $this->keys($pub));
        $tasks = $this->db->select('SELECT id,task_key FROM analytics_tasks WHERE publication_id=? ORDER BY id', [$pub]);
        foreach ($tasks as $task) {
            $collector->run((int)$task['id']);
        }
        self::assertSame(1, $this->db->table('post_stats_snapshots')->count());
        $row = $this->db->select('SELECT t.task_key,s.observed_at FROM post_stats_snapshots s JOIN analytics_tasks t ON t.id=s.task_id')[0];
        self::assertSame('post:'.$pub.':168', $row['task_key']);
        self::assertSame(DbTime::format($this->clock->now()), $row['observed_at']);
        self::assertSame(2, $this->db->table('analytics_tasks')->where('error_code', '=', 'milestone_missed')->count());
    }

    public function testFirstEnableAtTwentyNineDaysCollectsOnlyLatestMilestone(): void
    {
        [,,,$pub] = $this->published();
        $this->clock->advance(29 * 86400);
        $collector = $this->app->container()->get(StatsCollector::class);
        $collector->tick();
        $collector->tick();
        self::assertCount(1, $this->keys($pub));
        // Even clock rollback / a later existing snapshot must never backfill earlier observations.
        $this->clock->advance(-86400 * 20);
        $collector->tick();
        self::assertSame(['post:'.$pub.':672'], $this->keys($pub));
    }

    public function testRateLimitDefersSameJobWithoutSpendingRetriesAndPublishIsIndependent(): void
    {
        [$ctx,$channel] = $this->published();
        $collector = $this->app->container()->get(StatsCollector::class);
        $collector->tick(); // daily followers only
        $worker = $this->app->container()->get(Worker::class);
        $limiter = $this->app->container()->get(RateLimiter::class);
        for ($i = 0; $i < 60; ++$i) {
            $limiter->attempt('stats-platform:fake', 60, 60, failClosed:true);
        }
        $fake = $this->app->container()->get(FakeStatsFetcher::class);
        $before = $fake->calls;
        for ($i = 0; $i < 7; ++$i) {
            self::assertTrue($worker->runNext('analytics', 'deferral-test'));
            $row = $this->db->select("SELECT * FROM jobs WHERE queue='analytics'")[0];
            self::assertSame(0, (int)$row['attempts']);
            self::assertNull($row['last_error']);
            self::assertGreaterThan(DbTime::format($this->clock->now()), $row['available_at']);
            self::assertFalse($worker->runNext('analytics', 'deferral-test')); // no busy loop
            $this->clock->advance(61);
            if ($i < 6) {
                for ($j = 0; $j < 60; ++$j) {
                    $limiter->attempt('stats-platform:fake', 60, 60, failClosed:true);
                }
            }
        }
        self::assertSame($before, $fake->calls);
        self::assertSame(0, $this->db->table('failed_jobs')->count());
        $post = $this->service()->schedule($ctx, null, $this->draft('Queue isolation', [$channel]), $this->in('+1 minute'));
        $this->clock->advance(61);
        self::assertSame(1, $this->drain());
        self::assertSame('sent', $this->publications()->forPost($ctx, $post)[0]->status->value);
        self::assertTrue($worker->runNext('analytics', 'deferral-test'));
        self::assertSame(1, $this->snapshotCount());
        // A duplicate delivery is harmless, including across fresh worker instances.
        $task = (int)$this->db->select('SELECT id FROM analytics_tasks')[0]['id'];
        $this->app->container()->get(Queue::class)->dispatch(new StatsJob($task), queue:'analytics');
        $worker->runNext('analytics', 'other-worker');
        self::assertCount(1, $this->db->select('SELECT id FROM channel_stats_snapshots'));
    }

    public function testProviderFailureSpendsAnAttemptButCapacityFailureDoesNot(): void
    {
        [$ctx,$channel] = $this->published();
        $collector = $this->app->container()->get(StatsCollector::class);
        $collector->tick();
        $fake = $this->app->container()->get(FakeStatsFetcher::class);
        $fake->fail = true;
        $worker = $this->app->container()->get(Worker::class);
        $worker->runNext('analytics', 'failure-test');
        $row = $this->db->select("SELECT attempts,last_error FROM jobs WHERE queue='analytics'")[0];
        self::assertSame(1, (int)$row['attempts']);
        self::assertNotNull($row['last_error']);
        // A different workspace has independent channel tasks, but the same platform capacity budget.
        [$owner,$workspace] = $this->ownerWithWorkspace('other-analytics@example.com');
        $other = $this->fakeChannel($workspace, $owner, 'other');
        $this->app->container()->get(RateLimiter::class)->attempt('stats:fake:'.$channel->id, 1, 60);
        self::assertTrue($this->app->container()->get(RateLimiter::class)->attempt('stats:fake:'.$other->id, 1, 60)->allowed);
        self::assertTrue($this->app->container()->get(RateLimiter::class)->attempt('stats-platform:vk', 60, 60)->allowed);
    }

    public function testBestTimeNarrowQueryMatchesReportWithoutPostsOrFollowersTables(): void
    {
        [$ctx,$channel,,$pub] = $this->published();
        for ($i = 0; $i < 19; ++$i) {
            $this->scheduled($ctx, [$channel], '+1 minute');
        }
        $this->clock->advance(61);
        $this->drain();
        $this->clock->advance(3600);
        $collector = $this->app->container()->get(StatsCollector::class);
        $collector->tick();
        $worker = $this->app->container()->get(Worker::class);
        while ($worker->runNext('analytics', 'best-time-test')) {
        }
        $report = $this->app->container()->get(PostAnalytics::class)->report($ctx);
        $hints = $this->app->container()->get(BestTimeHints::class);
        $this->db->execute('RENAME TABLE posts TO analytics_test_posts, channel_stats_snapshots TO analytics_test_followers');
        try {
            self::assertSame($report['best'], $hints->forWorkspace($ctx)['best']);
            self::assertNotNull($hints->forWorkspace($ctx)['best']['recommendation']);
        } finally {
            $this->db->execute('RENAME TABLE analytics_test_posts TO posts, analytics_test_followers TO channel_stats_snapshots');
        }
        $workspace = $this->workspaces->findById($ctx->workspaceId) ?? throw new \LogicException();
        $client = $this->memberOf($workspace, 'narrow-client@example.com', Role::Client, 'Клиент');
        self::assertSame(0, $hints->forWorkspace($this->contextFor($workspace, $client))['best']['count']);
        $this->app->container()->get(ChannelAccessRepository::class)->set($ctx, $client->id, [$channel->id]);
        self::assertSame(20, $hints->forWorkspace($this->contextFor($workspace, $client))['best']['count']);
        foreach (['Posts/CalendarController.php','Automation/QueueController.php'] as $path) {
            $source = file_get_contents(dirname(__DIR__, 3).'/src/Http/Controllers/'.$path);
            self::assertIsString($source);
            self::assertStringNotContainsString('->report(', $source);
        }
    }

    public function testInternalChannelIdIsNotInDashboardOrGuestComparison(): void
    {
        [$ctx,$channel,$post] = $this->published();
        $this->clock->advance(3600);
        $this->app->container()->get(StatsCollector::class)->tick();
        $worker = $this->app->container()->get(Worker::class);
        while ($worker->runNext('analytics', 'ui-regression')) {
        }
        $page = $this->get('/w/'.$ctx->workspacePublicId.'/analytics');
        self::assertSame(200, $page->status);
        self::assertStringNotContainsString($channel->title.' #'.$channel->id, $page->body);
        $this->db->execute("UPDATE subscriptions s JOIN plans p ON p.code='agency' SET s.plan_id=p.id WHERE s.workspace_id=?", [$ctx->workspaceId]);
        $guest = $this->app->container()->get(GuestLinks::class)->create($ctx, [$post->publicId], 24);
        $page = $this->get($guest.'/analytics');
        self::assertSame(200, $page->status);
        self::assertStringNotContainsString($channel->title.' #'.$channel->id, $page->body);
        self::assertStringContainsString($channel->title.' · fake', $page->body);
    }
    public function testContinuouslyRunningSchedulerKeepsAllRealMilestoneObservations(): void
    {
        [,,,$pub] = $this->published();
        $collector = $this->app->container()->get(StatsCollector::class);
        $previous = 0;
        foreach (StatsCollector::HOURS as $hour) {
            $this->clock->advance(($hour - $previous) * 3600);
            $collector->tick();
            $id = (int)$this->db->select('SELECT id FROM analytics_tasks WHERE task_key=?', ['post:'.$pub.':'.$hour])[0]['id'];
            $collector->run($id);
            $previous = $hour;
        }
        self::assertSame(8, $this->db->table('post_stats_snapshots')->count());
        self::assertCount(8, $this->keys($pub));
    }

    public function testUnavailableLimiterDefersFailClosedWithoutAttemptsOrSnapshots(): void
    {
        $this->published();
        $this->app->container()->instance(RateLimiter::class, new RateLimiter(new \Redis(), $this->clock));
        $collector = $this->app->container()->get(StatsCollector::class);
        $collector->tick();
        $this->app->container()->get(Worker::class)->runNext('analytics', 'unavailable-limiter');
        $job = $this->db->select("SELECT attempts,available_at,last_error FROM jobs WHERE queue='analytics'")[0];
        self::assertSame(0, (int)$job['attempts']);
        self::assertNull($job['last_error']);
        self::assertGreaterThan(DbTime::format($this->clock->now()), $job['available_at']);
        self::assertSame(0, $this->db->table('channel_stats_snapshots')->count());
    }

}
