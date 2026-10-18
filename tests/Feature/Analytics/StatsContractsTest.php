<?php

declare(strict_types=1);

namespace App\Tests\Feature\Analytics;

use App\Domain\Analytics\{TelegramReactions,StatsCollector};
use App\Integrations\Social\Contracts\{Credential,Platform,PlatformError};
use App\Integrations\Social\Vk\{VkStatsFetcher,VkApi,VkRateGate};
use App\Integrations\Social\Telegram\TelegramStatsFetcher;
use App\Integrations\Social\Max\MaxStatsFetcher;
use App\Integrations\Social\Instagram\InstagramStatsFetcher;
use App\Tests\Support\BillingTestCase;

final class StatsContractsTest extends BillingTestCase
{
    private function channel(): \App\Domain\Channel\Channel
    {
        [$owner,$workspace] = $this->ownerWithWorkspace();
        return $this->fakeChannel($workspace, $owner);
    }
    public function testVkCountersOptionalReachAndRetryableRateLimit(): void
    {
        $c = $this->channel();
        $f = new VkStatsFetcher(new VkApi($this->http, new VkRateGate()));
        $credential = new Credential(Platform::Vk, 'fixture-token');
        $wall = '{"response":[{"views":{"count":100},"likes":{"count":0},"comments":{"count":2},"reposts":{"count":1}}]}';
        $this->http->expect(
            'POST',
            VkApi::API_BASE.'wall.getById',
            200,
            $wall
        )->expect(
            'POST',
            VkApi::API_BASE.'stats.getPostReach',
            200,
            '{"error":{"error_code":15,"error_msg":"Access denied"}}'
        );
        $m = $f->post($c, $credential, '1');
        self::assertNull($m->reach);
        self::assertSame(100, $m->views);
        self::assertSame(0, $m->likes);
        self::assertSame(3, $m->engagement);
        $this->http->expect('POST', VkApi::API_BASE.'groups.getById', 200, '{"response":{"groups":[{"members_count":12}]}}');
        self::assertSame(12, $f->followers($c, $credential));
        $this->http->expect('POST', VkApi::API_BASE.'wall.getById', 200, '{"error":{"error_code":6,"error_msg":"Too many requests"}}');
        $this->expectException(PlatformError::class);
        $f->post($c, $credential, '1');
    }
    public function testTelegramNoInventedViewsFollowersAndReactionUpdatesAreMonotonic(): void
    {
        $c = $this->channel();
        $f = $this->app->container()->get(TelegramStatsFetcher::class);
        $credential = new Credential(Platform::Telegram, '123:fixture');
        self::assertNull($f->post($c, $credential, '1')->views);
        self::assertNull($f->post($c, $credential, '1')->engagement);
        $this->http->expect('POST', 'https://api.telegram.org/bot123:fixture/getChatMemberCount', 200, '{"ok":true,"result":0}');
        self::assertSame(0, $f->followers($c, $credential));
        $this->db->execute("UPDATE channels SET platform='telegram',mode='shared_bot',external_id='-100123' WHERE id=?", [$c->id]);
        $reactions = $this->app->container()->get(TelegramReactions::class);
        $e = ['chat' => ['id' => -100123],'message_id' => 1,'date' => 200,'reactions' => [['total_count' => 8]]];
        $reactions->ingest($e);
        $e['date'] = 100;
        $e['reactions'] = [['total_count' => 2]];
        $reactions->ingest($e);
        self::assertSame(8, $reactions->count($c->workspaceId, $c->id, '1'));
        self::assertNull($reactions->count($c->workspaceId + 1, $c->id, '1'));
    }
    public function testMaxOptionalStatsAndInstagramInsightsContracts(): void
    {
        $c = $this->channel();
        $max = $this->app->container()->get(MaxStatsFetcher::class);
        $this->http->expect('GET', 'https://platform-api2.max.ru/messages/mid', 200, '{"body":{"mid":"mid"},"stat":{"views":25,"reposts":2}}');
        $m = $max->post($c, new Credential(Platform::Max, 'fixture'), 'mid');
        self::assertSame(25, $m->views);
        self::assertNull($m->engagement);
        $this->http->expect('GET', 'https://platform-api2.max.ru/messages/mid', 200, '{"body":{"mid":"mid"}}');
        self::assertNull($max->post($c, new Credential(Platform::Max, 'fixture'), 'mid')->views);
        $this->http->expect('GET', 'https://platform-api2.max.ru/chats/'.(int)$c->externalId, 200, '{"participants_count":50}');
        self::assertSame(50, $max->followers($c, new Credential(Platform::Max, 'fixture')));
        $ig = $this->app->container()->get(InstagramStatsFetcher::class);
        foreach (['views' => 100,'reach' => 50,'total_interactions' => 5,'likes' => 2,'comments' => 1,'shares' => 2] as $name => $value) {
            $this->http->expect(
                'GET',
                'https://graph.instagram.com/v23.0/123/insights',
                200,
                json_encode(['data' => [['name' => $name,'values' => [['value' => $value]]]]], JSON_THROW_ON_ERROR)
            );
        }
        $m = $ig->post($c, new Credential(Platform::Instagram, 'fixture'), '123');
        self::assertSame(10.0, $m->er());
        self::assertSame(100, $m->views);
        $this->http->expect('GET', 'https://graph.instagram.com/v23.0/'.$c->externalId, 200, '{"followers_count":15}');
        self::assertSame(15, $ig->followers($c, new Credential(Platform::Instagram, 'fixture')));
    }
    public function testLeaseCrashRecoveryAndOneUnavailableChannelDoNotBlockAnother(): void
    {
        [$owner,$workspace] = $this->ownerWithWorkspace();
        $a = $this->fakeChannel($workspace, $owner, 'a');
        $b = $this->fakeChannel($workspace, $owner, 'b');
        $collector = $this->app->container()->get(StatsCollector::class);
        $collector->tick();
        $tasks = $this->db->select('SELECT id FROM analytics_tasks ORDER BY id');
        $this->db->execute("UPDATE channels SET platform='telegram',mode='own_bot',credential_id=NULL WHERE id=?", [$a->id]);
        $collector->run((int)$tasks[0]['id']); // missing credential becomes safe unavailable, not an exception.
        $this->db->execute(
            'UPDATE analytics_tasks SET lease_token=?,lease_until=? WHERE id=?',
            [str_repeat(
                'a',
                32
            ),
            $this->clock->now()->modify('+1 minute')->format('Y-m-d H:i:s.u'),
            $tasks[1]['id']]
        );
        $collector->run((int)$tasks[1]['id']);
        self::assertSame(0, $this->db->table('channel_stats_snapshots')->count());
        $this->clock->advance(61);
        $collector->run((int)$tasks[1]['id']);
        self::assertSame(1, $this->db->table('channel_stats_snapshots')->count());
    }
    public function testOperationalCountersRecoveryAndPausedChannelAreTenantScoped(): void
    {
        [$owner,$workspace] = $this->ownerWithWorkspace();
        $channel = $this->fakeChannel($workspace, $owner);
        $collector = $this->app->container()->get(StatsCollector::class);
        $collector->tick();
        $ops = $this->app->container()->get(\App\Domain\Analytics\StatsOperations::class);
        self::assertSame(1, $ops->forWorkspace($workspace->id)['pending']);
        self::assertSame(0, $ops->forWorkspace($workspace->id + 1)['pending']);
        $task = (int)$this->db->select('SELECT id FROM analytics_tasks')[0]['id'];
        $this->db->execute(
            'UPDATE analytics_tasks SET enqueued_at=? WHERE id=?',
            [$this->clock->now()->modify('-7 hours')->format('Y-m-d H:i:s.u'),
            $task]
        );
        $collector->tick();
        self::assertSame(1, $this->db->table('analytics_tasks')->count());
        $this->db->execute("UPDATE channels SET status='paused' WHERE id=?", [$channel->id]);
        $collector->run($task);
        self::assertSame('channel_unavailable', $this->db->select('SELECT error_code FROM analytics_tasks WHERE id=?', [$task])[0]['error_code']);
        self::assertSame(0, $ops->forWorkspace($workspace->id)['pending']);
    }

}
