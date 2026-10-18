<?php

declare(strict_types=1);

namespace App\Tests\Feature\Analytics;

use App\Domain\Analytics\{PostAnalytics,StatsCollector,StatsJob,AnalyticsPdf};
use App\Domain\Approval\GuestLinks;
use App\Domain\Workspace\{Role,ChannelAccessRepository};
use App\Integrations\Social\Fake\FakeStatsFetcher;
use App\Kernel\Queue\Worker;
use App\Support\DbTime;
use App\Tests\Support\BillingTestCase;

final class PostAnalyticsTest extends BillingTestCase
{
    /** @return array{\App\Domain\Workspace\WorkspaceContext,\App\Domain\Post\Post,\App\Domain\Channel\Channel,int} */
    private function fixture(): array
    {
        [$owner,$workspace] = $this->ownerWithWorkspace();
        $this->payWithFake($workspace, $owner);
        $ctx = $this->contextFor($workspace, $owner);
        $channel = $this->fakeChannel($workspace, $owner);
        [$post,$pubs] = $this->scheduled($ctx, [$channel], '+1 minute', 'Analytics secret');
        $this->clock->advance(61);
        $this->drain();
        $this->clock->advance(3700);
        $this->actAs($owner);
        return [$ctx,$post,$channel,$pubs[0]->id];
    }
    private function collector(): StatsCollector
    {
        return $this->app->container()->get(StatsCollector::class);
    }
    private function report(): PostAnalytics
    {
        return $this->app->container()->get(PostAnalytics::class);
    }
    private function drainStats(): void
    {
        $w = $this->app->container()->get(Worker::class);
        for ($i = 0;$i < 20 && $w->runNext('analytics', 'stats-test');++$i) {
        }
    }
    public function testSchedulerQueueSnapshotsRetryDedupAndDashboard(): void
    {
        [$ctx,$post,$channel,$pub] = $this->fixture();
        $this->collector()->tick();
        $this->collector()->tick();
        self::assertSame(2, $this->db->table('analytics_tasks')->count());
        $fake = $this->app->container()->get(FakeStatsFetcher::class);
        $fake->fail = true;
        $this->drainStats();
        self::assertSame(0, $this->db->table('post_stats_snapshots')->count());
        $fake->fail = false;
        $this->clock->advance(301);
        $this->drainStats();
        self::assertSame(1, $this->db->table('post_stats_snapshots')->count());
        self::assertSame(1, $this->db->table('channel_stats_snapshots')->count());
        $id = (int)$this->db->select('SELECT id FROM analytics_tasks WHERE publication_id IS NOT NULL')[0]['id'];
        $this->collector()->run($id);
        self::assertSame(1, $this->db->table('post_stats_snapshots')->count());
        $data = $this->report()->report($ctx);
        self::assertSame(10.0, $data['totals']['er']);
        self::assertSame(1, $data['totals']['publications']);
        $page = $this->get('/w/'.$ctx->workspacePublicId.'/analytics');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('Analytics secret', $page->body);
        self::assertStringContainsString('Мало данных', $page->body);
    }
    public function testChannelClientAndForeignWorkspaceCannotReadMetrics(): void
    {
        [$ctx,$post,$channel,$pub] = $this->fixture();
        $this->collector()->tick();
        $this->drainStats();
        $workspace = $this->app->container()->get(\App\Domain\Workspace\WorkspaceRepository::class)->findById($ctx->workspaceId) ?? throw new \LogicException();
        $client = $this->memberOf($workspace, 'stats-client@example.com', Role::Client, 'Клиент');
        $clientCtx = $this->contextFor($workspace, $client);
        self::assertSame(0, $this->report()->report($clientCtx)['totals']['publications']);
        $this->app->container()->get(ChannelAccessRepository::class)->set($ctx, $client->id, [$channel->id]);
        self::assertSame(1, $this->report()->report($clientCtx)['totals']['publications']);
        [$other,$ws] = $this->ownerWithWorkspace('stats-other@example.com');
        $foreign = $this->contextFor($ws, $other);
        self::assertSame(0, $this->report()->report($foreign)['totals']['publications']);
        $this->actAs($other);
        self::assertSame(404, $this->get('/w/'.$ctx->workspacePublicId.'/analytics')->status);
    }
    public function testRetentionSevenThirtyAndYearNeverDeletesFreshSnapshotsOrOtherWorkspace(): void
    {
        [$ctx,$post,$channel,$pub] = $this->fixture();
        $this->collector()->tick();
        $this->drainStats();
        $workspace = $this->workspaces->findById($ctx->workspaceId) ?? throw new \LogicException();
        foreach ([7,30,365] as $days) {
            $this->givePlan($workspace, 'pro', ['analytics_days' => $days]);
            self::assertSame($days, $this->collector()->days($ctx->workspaceId));
            $this->db->execute(
                'UPDATE post_stats_snapshots SET observed_at=?',
                [DbTime::format($this->clock->now()->modify('-'.($days - 1).' days'))]
            );
            $this->collector()->prune();
            self::assertSame(1, $this->db->table('post_stats_snapshots')->count());
        }
        $this->db->execute('UPDATE post_stats_snapshots SET observed_at=?', [DbTime::format($this->clock->now()->modify('-366 days'))]);
        $this->collector()->prune();
        self::assertSame(0, $this->db->table('post_stats_snapshots')->count());
    }
    public function testCsvAndPdfAreEntitlementGatedAndGuestUsesExistingPostScope(): void
    {
        [$ctx,$post,$channel,$pub] = $this->fixture();
        $this->collector()->tick();
        $this->drainStats();
        $url = '/w/'.$ctx->workspacePublicId.'/analytics';
        self::assertSame(200, $this->get($url.'/export.csv')->status);
        $this->http->expect('POST', 'http://analytics-pdf:3000/pdf', 200, '%PDF-1.7 test');
        self::assertSame(200, $this->get($url.'/export.pdf')->status);
        $last = $this->http->requests[array_key_last($this->http->requests) ?? 0] ?? throw new \LogicException('Missing renderer request');
        self::assertSame(
            hash_hmac(
                'sha256',
                $last['options']['body'],
                \App\Domain\Analytics\AnalyticsPdfKey::derive(\App\Tests\Support\TestEnv::KEY)
            ),
            $last['options']['headers']['X-Report-Signature']
        );
        $links = $this->app->container()->get(GuestLinks::class);
        $guest = $links->create($ctx, [$post->publicId], 24);
        self::assertSame(403, $this->get($guest.'/analytics')->status); // Pro has no client_reports.
        $this->db->execute("UPDATE subscriptions s JOIN plans p ON p.code='agency' SET s.plan_id=p.id WHERE s.workspace_id=?", [$ctx->workspaceId]);
        self::assertSame(200, $this->get($guest.'/analytics')->status);
        $this->db->execute('UPDATE guest_links SET revoked_at=? WHERE workspace_id=?', [DbTime::format($this->clock->now()),$ctx->workspaceId]);
        self::assertSame(404, $this->get($guest.'/analytics')->status);
    }
    public function testMilestonePlanAndDateRetentionGuards(): void
    {
        [$ctx,$post,$channel,$pub] = $this->fixture();
        $this->clock->advance(28 * 86400);
        $this->collector()->tick();
        self::assertSame(1, $this->db->table('analytics_tasks')->where('publication_id', '=', $pub)->count());
        $this->collector()->tick();
        self::assertCount(1, $this->db->select('SELECT task_key FROM analytics_tasks WHERE publication_id=?', [$pub]));
        $user = $this->app->container()->get(\App\Domain\User\UserRepository::class)->find($ctx->userId) ?? throw new \LogicException();
        $this->actAs($user);
        $old = $this->clock->now()->modify('-366 days')->format('Y-m-d');
        self::assertSame(422, $this->get('/w/'.$ctx->workspacePublicId.'/analytics?from='.$old)->status);
        self::assertSame(422, $this->get('/w/'.$ctx->workspacePublicId.'/analytics?from=bad-date')->status);
        self::assertSame(404, $this->get('/w/'.$ctx->workspacePublicId.'/analytics?channel=01M00000000000000000000000')->status);
    }

    public function testPdfUnavailableOrInvalidResponseFailsSafely(): void
    {
        [$ctx] = $this->fixture();
        $url = '/w/'.$ctx->workspacePublicId.'/analytics/export.pdf';
        $this->http->expect('POST', 'http://analytics-pdf:3000/pdf', 503, 'do not expose internal detail');
        $r = $this->get($url);
        self::assertSame(503, $r->status);
        self::assertStringNotContainsString('internal detail', $r->body);
        $this->http->expect('POST', 'http://analytics-pdf:3000/pdf', 200, 'not a pdf');
        self::assertSame(503, $this->get($url)->status);
    }

}
