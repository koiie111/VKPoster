<?php

declare(strict_types=1);

namespace App\Tests\Integration\Kernel;

use App\Kernel\Database\Connection;
use App\Tests\Support\TestEnv;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Connection settings, query builder against real MySQL, nested transactions.
 */
#[CoversClass(Connection::class)]
#[CoversClass(\App\Kernel\Database\QueryBuilder::class)]
final class DatabaseTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        $this->db = TestEnv::connection();
        $this->db->execute('DROP TABLE IF EXISTS qb_test');
        $this->db->execute('CREATE TABLE qb_test (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50) NOT NULL, score INT NULL, seen_at DATETIME(6) NULL) ENGINE=InnoDB');
    }

    protected function tearDown(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS qb_test');
    }

    public function testConnectionIsConfiguredSafely(): void
    {
        $pdo = $this->db->pdo();

        self::assertSame(PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(PDO::ATTR_ERRMODE));
        self::assertFalse((bool) $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES));
        $row = $this->db->select('SELECT @@session.time_zone AS tz, @@character_set_client AS cs')[0];
        self::assertSame('+00:00', $row['tz']);
        self::assertSame('utf8mb4', $row['cs']);
    }

    public function testCrudThroughBuilder(): void
    {
        $t = $this->db->table('qb_test');
        $id = (int) $t->insert(['name' => 'ann', 'score' => 5]);
        $this->db->table('qb_test')->insert(['name' => "o'brien; DROP TABLE qb_test --", 'score' => null]);
        $this->db->table('qb_test')->insert(['name' => 'zed', 'score' => 9]);

        self::assertSame(3, $this->db->table('qb_test')->count());
        self::assertSame('ann', $this->db->table('qb_test')->where('id', '=', $id)->first()['name'] ?? null);
        self::assertSame(
            ["o'brien; DROP TABLE qb_test --"],
            array_column($this->db->table('qb_test')->whereNull('score')->get(), 'name'),
            'values with quotes are bound, not interpolated',
        );
        self::assertSame(['zed', 'ann'], array_column($this->db->table('qb_test')->whereNotNull('score')->orderBy('score', 'desc')->get(), 'name'));
        self::assertSame(['ann', 'zed'], array_column($this->db->table('qb_test')->whereIn('score', [5, 9])->orderBy('id')->get(), 'name'));
        self::assertSame([], $this->db->table('qb_test')->whereIn('id', [])->get());
        self::assertSame(['zed'], array_column($this->db->table('qb_test')->orderBy('id', 'desc')->limit(1)->offset(0)->get(), 'name'));
        self::assertSame(1, $this->db->table('qb_test')->where('id', '=', $id)->update(['score' => 6]));
        self::assertSame(6, $this->db->table('qb_test')->select(['score'])->where('id', '=', $id)->first()['score'] ?? null);
        self::assertSame(1, $this->db->table('qb_test')->where('name', 'LIKE', 'a%')->delete());
        self::assertTrue($this->db->table('qb_test')->where('name', '=', 'zed')->exists());
        self::assertFalse($this->db->table('qb_test')->where('name', '=', 'nobody')->exists());
    }

    public function testInjectionAttemptInValueDoesNotAlterQuery(): void
    {
        $this->db->table('qb_test')->insert(['name' => 'ann']);

        $rows = $this->db->table('qb_test')->where('name', '=', "' OR '1'='1")->get();

        self::assertSame([], $rows);
    }

    public function testTransactionCommitsAndReturnsValue(): void
    {
        $result = $this->db->transaction(function (Connection $db): string {
            $db->table('qb_test')->insert(['name' => 'in-tx']);

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame(1, $this->db->table('qb_test')->count());
    }

    public function testTransactionRollsBackOnException(): void
    {
        try {
            $this->db->transaction(function (Connection $db): void {
                $db->table('qb_test')->insert(['name' => 'lost']);
                $this->failNow('boom');
            });
            self::fail('exception expected');
        } catch (RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        self::assertSame(0, $this->db->table('qb_test')->count());
    }

    private function failNow(string $message, bool $fail = true): void
    {
        if ($fail) {
            throw new RuntimeException($message);
        }
    }

    public function testNestedTransactionRollsBackOnlyInnerWork(): void
    {
        $this->db->transaction(function (Connection $db): void {
            $db->table('qb_test')->insert(['name' => 'outer']);
            try {
                $db->transaction(function (Connection $db): void {
                    $db->table('qb_test')->insert(['name' => 'inner']);
                    $this->failNow('inner failed');
                });
            } catch (RuntimeException) {
                // swallowed: the outer transaction continues
            }
        });

        self::assertSame(['outer'], array_column($this->db->table('qb_test')->get(), 'name'));
    }

    public function testTimestampsRoundTripInUtc(): void
    {
        $this->db->table('qb_test')->insert(['name' => 't', 'seen_at' => '2026-01-01 12:00:00.123456']);

        $row = $this->db->select('SELECT seen_at, UNIX_TIMESTAMP(seen_at) AS ts FROM qb_test')[0];

        self::assertSame('2026-01-01 12:00:00.123456', $row['seen_at']);
        self::assertSame(1767268800, (int) $row['ts']);
    }
}
