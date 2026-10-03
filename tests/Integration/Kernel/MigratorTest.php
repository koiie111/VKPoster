<?php

declare(strict_types=1);

namespace App\Tests\Integration\Kernel;

use App\Kernel\Database\Connection;
use App\Kernel\Database\Migrator;
use App\Support\Fs;
use App\Tests\Support\TestEnv;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Migrator: ordering, batches, rollback, status, plus the real migrate → rollback → migrate cycle.
 */
#[CoversClass(Migrator::class)]
final class MigratorTest extends TestCase
{
    private Connection $db;
    private string $dir;

    protected function setUp(): void
    {
        $this->db = TestEnv::connection();
        $this->dir = sys_get_temp_dir() . '/vkposter-mig-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->db->execute('DROP TABLE IF EXISTS mig_a');
        $this->db->execute('DROP TABLE IF EXISTS mig_b');
        $this->db->execute('DROP TABLE IF EXISTS migrations_test');
        $this->write('2026_01_01_000001_a', 'mig_a');
        $this->write('2026_01_01_000002_b', 'mig_b');
    }

    protected function tearDown(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS mig_a');
        $this->db->execute('DROP TABLE IF EXISTS mig_b');
        $this->db->execute('DROP TABLE IF EXISTS migrations_test');
        array_map('unlink', Fs::glob($this->dir . '/*.php'));
        rmdir($this->dir);
    }

    private function write(string $name, string $table): void
    {
        file_put_contents($this->dir . '/' . $name . '.php', <<<PHP
            <?php
            declare(strict_types=1);
            use App\Kernel\Database\Connection;
            use App\Kernel\Database\Migration;
            return new class () implements Migration {
                public function up(Connection \$db): void { \$db->execute('CREATE TABLE {$table} (id INT PRIMARY KEY)'); }
                public function down(Connection \$db): void { \$db->execute('DROP TABLE {$table}'); }
            };
            PHP);
    }

    private function tableExists(string $table): bool
    {
        return $this->db->select('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table]) !== [];
    }

    public function testMigrateAppliesInOrderOnceAndRollbackRevertsLastBatch(): void
    {
        $migrator = new Migrator($this->db, $this->dir, 'migrations_test');

        self::assertSame(['2026_01_01_000001_a', '2026_01_01_000002_b'], $migrator->migrate());
        self::assertTrue($this->tableExists('mig_a') && $this->tableExists('mig_b'));
        self::assertSame([], $migrator->migrate(), 'second run is a no-op');

        $this->write('2026_01_01_000003_c', 'mig_c');
        self::assertSame(['2026_01_01_000003_c'], $migrator->migrate());
        self::assertSame(['2026_01_01_000003_c'], $migrator->rollback(), 'only the last batch is reverted');
        self::assertTrue($this->tableExists('mig_a'));
        self::assertFalse($this->tableExists('mig_c'));

        self::assertSame(['2026_01_01_000002_b', '2026_01_01_000001_a'], $migrator->rollback(), 'newest first');
        self::assertFalse($this->tableExists('mig_a'));
        self::assertSame([], $migrator->rollback());
        unlink($this->dir . '/2026_01_01_000003_c.php');
    }

    public function testStatusReportsPendingAndApplied(): void
    {
        $migrator = new Migrator($this->db, $this->dir, 'migrations_test');
        $migrator->migrate();
        $this->write('2026_01_01_000009_z', 'mig_z');

        $status = $migrator->status();

        self::assertSame(['applied', 'applied', 'pending'], array_map(static fn (array $r): string => $r['applied'] ? 'applied' : 'pending', $status));
        self::assertSame(1, $status[0]['batch']);
        self::assertNull($status[2]['batch']);
        unlink($this->dir . '/2026_01_01_000009_z.php');
    }

    public function testRealMigrationsAreReversible(): void
    {
        $migrator = new Migrator($this->db, TestEnv::basePath() . '/database/migrations');

        $migrator->migrate();
        self::assertTrue($this->tableExists('jobs'));
        $reverted = $migrator->rollback();
        self::assertNotSame([], $reverted);
        self::assertFalse($this->tableExists('jobs'));
        self::assertNotSame([], $migrator->migrate());
        self::assertTrue($this->tableExists('jobs') && $this->tableExists('failed_jobs'));
    }
}
