<?php

declare(strict_types=1);

namespace App\Tests\Integration\Source;

use App\Kernel\Database\Migrator;
use App\Tests\Support\TestEnv;
use PHPUnit\Framework\TestCase;

/** Replays the consolidated Stage 22 batch on a main-schema database without resetting application data. */
final class Stage22SchemaTest extends TestCase
{
    public function testMainUpgradeRollbackReplayAndExplicitCollations(): void
    {
        $db = TestEnv::connection();
        self::assertSame('app_test', $db->select('SELECT DATABASE() AS name')[0]['name']);
        $files = glob(TestEnv::basePath() . '/database/migrations/*.php');
        if ($files === false) {
            throw new \LogicException('Missing migration directory');
        }
        sort($files);
        $stage = array_values(array_filter($files, static fn (string $p): bool => (int) explode('_', basename($p))[3] >= 21));
        self::assertCount(11, $stage);
        foreach (array_reverse($stage) as $file) {
            (require $file)->down($db);
        }
        $directory = sys_get_temp_dir() . '/stage22-' . bin2hex(random_bytes(6));
        mkdir($directory);
        foreach ($stage as $file) {
            copy($file, $directory . '/' . basename($file));
        }
        $migrator = new Migrator($db, $directory, 'migrations_stage22_test');
        try {
            self::assertCount(11, $migrator->migrate(), 'Upgrade from intact main tables');
            $rows = $db->select("SELECT TABLE_NAME,TABLE_COLLATION FROM information_schema.tables WHERE TABLE_SCHEMA=DATABASE() AND (TABLE_NAME LIKE 'source%' OR TABLE_NAME LIKE 'semantic%' OR TABLE_NAME LIKE 'discovery%' OR TABLE_NAME LIKE 'content_%')");
            foreach ($rows as $row) {
                self::assertSame('utf8mb4_unicode_ci', $row['TABLE_COLLATION'], (string) $row['TABLE_NAME']);
            }
            self::assertCount(11, $migrator->rollback());
            self::assertSame([], $db->select("SHOW TABLES LIKE 'source_items'"));
            self::assertCount(11, $migrator->migrate());
            self::assertSame([], $migrator->migrate());
        } finally {
            $db->execute('DROP TABLE IF EXISTS migrations_stage22_test');
            foreach ($stage as $file) {
                unlink($directory . '/' . basename($file));
            }
            rmdir($directory);
        }
    }
}
