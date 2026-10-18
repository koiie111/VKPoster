<?php

declare(strict_types=1);

namespace App\Tests\Feature\Analytics;

use App\Domain\Analytics\StatsCollector;
use App\Integrations\Social\Contracts\{Credential,Platform};
use App\Integrations\Social\Instagram\InstagramStatsFetcher;
use App\Tests\Support\BillingTestCase;

final class InstagramMetricRegressionTest extends BillingTestCase
{
    /**
     * @param array<string,int> $errors
     * @param list<string> $missing
     */
    private function answers(array $errors = [], array $missing = []): void
    {
        foreach (['views','reach','total_interactions','likes','comments','shares'] as $i => $metric) {
            $body = in_array($metric, $missing, true) ? ['data' => []] : ['data' => [['name' => $metric,'total_value' => ['value' => $i]]]];
            if (isset($errors[$metric])) {
                $body = ['error' => ['code' => $errors[$metric],'type' => 'fixture']];
            }
            $this->http->expect('GET', 'https://graph.instagram.com/v23.0/123/insights', 200, json_encode($body, JSON_THROW_ON_ERROR));
        }
    }

    public function testUnsupportedAndPermissionDeniedDoNotDiscardOtherCounters(): void
    {
        [$owner,$workspace] = $this->ownerWithWorkspace();
        $channel = $this->fakeChannel($workspace, $owner);
        $this->answers(['views' => 100,'likes' => 10]);
        $metrics = $this->app->container()->get(InstagramStatsFetcher::class)->post($channel, new Credential(Platform::Instagram, 'fixture'), '123');
        self::assertNull($metrics->views);
        self::assertNull($metrics->likes);
        self::assertSame('unsupported', $metrics->statuses['views']);
        self::assertSame('permission_denied', $metrics->statuses['likes']);
        self::assertSame(1, $metrics->reach);
        self::assertSame(2, $metrics->engagement);
        foreach ($this->http->requests as $i => $request) {
            self::assertSame(['views','reach','total_interactions','likes','comments','shares'][$i], $request['options']['query']['metric']);
        }
        $this->http->assertAllConsumed();
    }

    public function testAllAvailableIncludeRealZeroAndAllUnavailableStayNull(): void
    {
        [$owner,$workspace] = $this->ownerWithWorkspace();
        $channel = $this->fakeChannel($workspace, $owner);
        $fetcher = $this->app->container()->get(InstagramStatsFetcher::class);
        $credential = new Credential(Platform::Instagram, 'fixture');
        $this->answers();
        $metrics = $fetcher->post($channel, $credential, '123');
        self::assertSame(0, $metrics->views);
        self::assertSame(array_fill(0, 6, 'available'), array_values($metrics->statuses));
        $this->answers([], ['views','reach','total_interactions','likes','comments','shares']);
        $metrics = $fetcher->post($channel, $credential, '123');
        self::assertSame(array_fill(0, 6, null), array_values($metrics->toArray()));
        self::assertSame(array_fill(0, 6, 'unavailable'), array_values($metrics->statuses));
        $this->answers(array_fill_keys(['views','reach','total_interactions','likes','comments','shares'], 190));
        $metrics = $fetcher->post($channel, $credential, '123');
        self::assertSame(array_fill(0, 6, 'unavailable'), array_values($metrics->statuses));
    }

    public function testTransientReadCarriesSuccessfulCountersAndDurablyPreservesProgress(): void
    {
        [$owner,$workspace] = $this->ownerWithWorkspace();
        $ctx = $this->contextFor($workspace, $owner);
        $channel = $this->fakeChannel($workspace, $owner);
        [,$pubs] = $this->scheduled($ctx, [$channel], '+1 minute');
        $this->clock->advance(61);
        $this->drain();
        $this->clock->advance(3600);
        $this->db->execute("UPDATE channels SET platform='instagram',mode='own_bot' WHERE id=?", [$channel->id]);
        // The actual credentials boundary is reused, with an encrypted synthetic token.
        $vault = $this->app->container()->get(\App\Domain\Channel\CredentialVault::class);
        $credentialId = $vault->store($ctx, Platform::Instagram, 'token', 'fixture');
        $this->db->execute('UPDATE channels SET credential_id=? WHERE id=?', [$credentialId,$channel->id]);
        $this->db->execute("UPDATE publications SET external_post_id='123' WHERE id=?", [$pubs[0]->id]);
        $collector = $this->app->container()->get(StatsCollector::class);
        $collector->tick();
        $task = (int)$this->db->select('SELECT id FROM analytics_tasks WHERE publication_id=?', [$pubs[0]->id])[0]['id'];
        foreach ([['views' => 2],['reach' => 2]] as $errors) {
            $this->answers($errors);
            try {
                $collector->run($task);
                self::fail('Temporary failure must retry');
            } catch (\RuntimeException $error) {
                self::assertSame('Statistics temporarily unavailable.', $error->getMessage());
            }
            $json = (string)$this->db->select('SELECT metrics_json FROM post_stats_snapshots WHERE task_id=?', [$task])[0]['metrics_json'];
            $saved = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
            self::assertSame(1, $saved['reach']); // preserved even when the second run cannot read reach
            self::assertSame(2, $saved['engagement']);
            self::assertNull($this->db->select('SELECT completed_at FROM analytics_tasks WHERE id=?', [$task])[0]['completed_at']);
        }
        $this->answers();
        $collector->run($task);
        self::assertSame(1, $this->db->table('post_stats_snapshots')->count());
        self::assertNotNull($this->db->select('SELECT completed_at FROM analytics_tasks WHERE id=?', [$task])[0]['completed_at']);
    }
}
