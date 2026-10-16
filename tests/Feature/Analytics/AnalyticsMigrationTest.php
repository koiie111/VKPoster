<?php

declare(strict_types=1);

namespace App\Tests\Feature\Analytics;

use App\Tests\Support\BillingTestCase;

final class AnalyticsMigrationTest extends BillingTestCase
{
    public function testUpgradeWithExistingPublishedPostAndRollbackPreservePublishingData(): void
    {
        [$owner,$workspace] = $this->ownerWithWorkspace();
        $ctx = $this->contextFor($workspace, $owner);
        $channel = $this->fakeChannel($workspace, $owner);
        [$post] = $this->scheduled($ctx, [$channel], '+1 minute', 'Existing before analytics');
        $this->clock->advance(61);
        $this->drain();
        $file = dirname(__DIR__, 3).'/database/migrations/2026_10_16_000036_create_post_analytics.php';
        $migration = require $file;
        try {
            $migration->down($this->db);
            self::assertSame(1, $this->db->table('publications')->where('status', '=', 'sent')->count());
            $migration->up($this->db);
            self::assertNotNull($this->posts()->find($ctx, $post->publicId));
            self::assertSame(1, $this->db->table('publications')->where('status', '=', 'sent')->count());
            self::assertSame(0, $this->db->table('post_stats_snapshots')->count());
        } finally {
            if ($this->db->select("SHOW TABLES LIKE 'analytics_tasks'") === []) {
                $migration->up($this->db);
            }
        }
    }
}
