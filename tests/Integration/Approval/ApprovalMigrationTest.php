<?php

declare(strict_types=1);

namespace App\Tests\Integration\Approval;

use App\Tests\Support\TestEnv;
use App\Tests\Support\PostTestCase;

/** Stage 14 CREATE rollback/replay, compatible collation and no destruction of ordinary posts. */
final class ApprovalMigrationTest extends PostTestCase
{
    public function testMainUpgradeRollbackReplay(): void
    {
        $db = TestEnv::connection();
        self::assertSame('app_test', $db->select('SELECT DATABASE() AS name')[0]['name']);
        $migration = require TestEnv::basePath().'/database/migrations/2026_10_14_000035_create_approvals.php';
        $posts = $db->table('posts')->count();
        $migration->down($db);
        try {
            self::assertSame([], $db->select("SHOW TABLES LIKE 'approvals'"));
            self::assertSame($posts, $db->table('posts')->count());
        } finally {
            $migration->up($db);
        }
        foreach (['workspace_approval_settings','post_versions','guest_links','guest_link_posts','approvals','post_comments'] as $table) {
            $collation = $db->select('SELECT TABLE_COLLATION FROM information_schema.tables WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table])[0]['TABLE_COLLATION'];
            self::assertSame('utf8mb4_unicode_ci', $collation);
        }
    }
}
