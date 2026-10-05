<?php

declare(strict_types=1);

namespace App\Tests\Feature\Admin;

use App\Domain\Admin\OperationalStats;
use App\Domain\Admin\StaffRole;
use App\Domain\Admin\SystemStatus;
use App\Http\Controllers\Admin\StatsController;
use App\Kernel\Config;
use App\Support\Clock;
use App\Support\DbTime;
use App\Support\Heartbeat;
use App\Tests\Support\AdminTestCase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Uid\Ulid;

/**
 * Publishing statistics on a hand-made set (ten sent with delays of 1 to 10 seconds, two failed, one unknown, one waiting), the hourly error
 * share, the system checks and the log reader.
 */
#[CoversClass(OperationalStats::class)]
#[CoversClass(SystemStatus::class)]
#[CoversClass(StatsController::class)]
#[CoversClass(Heartbeat::class)]
final class OperationalStatsTest extends AdminTestCase
{
    private int $workspaceId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['publication_attempts', 'publications', 'media'] as $table) {
            $this->db->execute('DELETE FROM ' . $table);
        }
        [$owner, $workspace] = $this->ownerWithWorkspace('ops@example.com');
        $channel = $this->fakeChannel($workspace, $owner, 'ops-1', 'Канал оператора');
        $this->workspaceId = $workspace->id;
        $due = $this->clock->now()->modify('-30 minutes');
        for ($i = 1; $i <= 10; $i++) {
            $this->publication($channel->id, 'sent', $due, $i);
        }
        $this->publication($channel->id, 'failed', $due, null, 'rate_limited');
        $this->publication($channel->id, 'failed', $due, null, 'auth');
        $this->publication($channel->id, 'unknown', $due, null, 'unknown_outcome');
        $this->publication($channel->id, 'queued', $due->modify('+2 hours'), null);
    }

    private function publication(int $channelId, string $status, DateTimeImmutable $due, ?int $latency, ?string $error = null): void
    {
        $now = DbTime::format($due);
        $this->db->execute("INSERT INTO posts (public_id, workspace_id, status, base_text, timezone, created_at, updated_at) VALUES (?, ?, 'published', 'x', 'UTC', ?, ?)", [(string) new Ulid(), $this->workspaceId, $now, $now]);
        $postId = (int) $this->db->lastInsertId();
        $this->db->execute("INSERT INTO post_variants (post_id, workspace_id, channel_id, platform, channel_name) VALUES (?, ?, ?, 'telegram', 'Канал оператора')", [$postId, $this->workspaceId, $channelId]);
        $variantId = (int) $this->db->lastInsertId();
        $sent = $latency === null ? null : DbTime::format($due->modify('+' . $latency . ' seconds'));
        $this->db->execute(
            'INSERT INTO publications (public_id, workspace_id, post_id, variant_id, channel_id, status, attempt, due_at, run_at, idempotency_key, error_code, sent_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?)',
            [(string) new Ulid(), $this->workspaceId, $postId, $variantId, $channelId, $status, $now, $now, hash('sha256', (string) new Ulid()), $error, $sent, $now, $now],
        );
        $publicationId = (int) $this->db->lastInsertId();
        if ($status !== 'queued') {
            $this->db->execute(
                'INSERT INTO publication_attempts (publication_id, workspace_id, attempt, outcome, error_kind, started_at, finished_at) VALUES (?, ?, 1, ?, NULL, ?, ?)',
                [$publicationId, $this->workspaceId, $status === 'sent' ? 'sent' : ($error === 'rate_limited' ? 'rate_limited' : ($error === 'auth' ? 'auth' : 'unknown')), $now, $sent ?? $now],
            );
        }
    }

    public function testPublicationNumbers(): void
    {
        $stats = $this->app->container()->get(OperationalStats::class);
        $now = $this->clock->now();
        $result = $stats->publications($now->modify('-1 day'), $now->modify('+1 day'));
        self::assertSame(['sent' => 10, 'failed' => 2, 'unknown' => 1, 'waiting' => 1, 'total' => 14, 'success' => 76.9], $result['total']);
        self::assertSame(76.9, $result['platforms']['telegram']['success']);
        self::assertSame(['p50' => 5.0, 'p95' => 10.0, 'count' => 10], $result['latency']['all']);
        self::assertSame(['telegram' => 1], $result['errors']['rate_limited']);
        self::assertSame(['telegram' => 1], $result['errors']['auth']);
        self::assertSame(3, $result['channels'][0]['failed']);
        self::assertSame('Канал оператора', $result['channels'][0]['title']);
        self::assertSame(3, $result['workspaces'][0]['failed']);
        // A period without publications has no success rate (not a made-up 0%).
        $none = $stats->publications($now->modify('-10 days'), $now->modify('-9 days'));
        self::assertNull($none['total']['success']);
        self::assertSame([], $none['platforms']);
    }

    public function testErrorShareByHour(): void
    {
        $result = $this->app->container()->get(OperationalStats::class)->errorsByHour($this->clock->now(), 6);
        self::assertCount(6, $result['hours']);
        $series = $result['platforms']['telegram'];
        self::assertSame(23.1, array_values(array_filter($series, static fn (?float $v): bool => $v !== null))[0], '3 of 13 attempts failed');
        self::assertSame(13, array_sum($result['attempts']['telegram']));
        self::assertSame(1, array_sum($result['rate_limited']['telegram']));
    }

    public function testPercentilesAreNearestRank(): void
    {
        self::assertSame(0.0, OperationalStats::percentile([], 95));
        self::assertSame(7.0, OperationalStats::percentile([7.0], 50));
        self::assertSame(2.0, OperationalStats::percentile([1.0, 2.0, 3.0, 4.0], 50));
        self::assertSame(100.0, OperationalStats::percentile(array_map('floatval', range(1, 100)), 100));
        self::assertSame(95.0, OperationalStats::percentile(array_map('floatval', range(1, 100)), 95));
    }

    public function testStatsPageAndSystemPageRenderForTheRolesThatMaySeeThem(): void
    {
        $this->staff(StaffRole::Analyst);
        $page = $this->plain($this->get('/admin/stats?days=1'));
        self::assertStringContainsString('76,9%', $page);
        self::assertStringContainsString('Лимит запросов сети', $page);
        self::assertStringContainsString('Канал оператора', $page);
        self::assertSame(200, $this->get('/admin/system')->status);

        $this->staff(StaffRole::Content);
        self::assertSame(403, $this->get('/admin/stats')->status);
        self::assertSame(403, $this->get('/admin/system')->status);
    }

    public function testSystemChecksSeeHeartbeatsAndOldQueue(): void
    {
        $this->clock->set(gmdate('Y-m-d H:i:s'));
        $status = $this->app->container()->get(SystemStatus::class);
        $byName = static fn (array $checks): array => array_column($checks, 'state', 'name');
        self::assertSame('bad', $byName($status->checks())['Обработчик задач'], 'nothing has reported yet');

        $beat = $this->app->container()->get(Heartbeat::class);
        $beat->beat('worker');
        $beat->beat('scheduler');
        $states = $byName($status->checks());
        self::assertSame('ok', $states['Обработчик задач']);
        self::assertSame('ok', $states['Планировщик']);
        self::assertSame('ok', $states['MySQL']);
        self::assertSame('ok', $states['Redis']);

        $this->clock->advance(SystemStatus::STALE_SECONDS + 60);
        self::assertSame('bad', $byName($status->checks())['Планировщик'], 'a process that fell silent is a problem');

        self::assertNull($status->queue()['oldest_seconds']);
        $past = DbTime::format($this->clock->now()->modify('-10 minutes'));
        $this->db->execute("INSERT INTO jobs (queue, payload_json, available_at, created_at) VALUES ('default', '{}', ?, ?)", [$past, $past]);
        $queue = $status->queue();
        self::assertSame(1, $queue['waiting']);
        self::assertSame(600, $queue['oldest_seconds']);
    }

    public function testLogReaderShowsOnlyErrorsWithoutContext(): void
    {
        $file = sys_get_temp_dir() . '/admin-log-' . bin2hex(random_bytes(4)) . '.log';
        $line = static fn (int $level, string $name, string $message, array $context): string => json_encode(['message' => $message, 'context' => $context, 'level' => $level, 'level_name' => $name, 'datetime' => '2026-10-05T10:00:00+00:00']) . "\n";
        file_put_contents($file, $line(200, 'INFO', 'fine', []) . $line(400, 'ERROR', 'http.unhandled_exception', ['exception' => ['class' => 'RuntimeException', 'file' => '/var/www/html/src/Domain/X.php'], 'token' => 'SECRET', 'email' => 'a@b.c']) . 'not json' . "\n" . $line(500, 'CRITICAL', 'db.down', ['path' => '/app']));
        $status = new SystemStatus($this->db, $this->app->container()->get(\Redis::class), $this->app->container()->get(Heartbeat::class), $this->app->container()->get(Clock::class), $this->app->container()->get(Config::class), $file, sys_get_temp_dir());
        $errors = $status->errors();
        @unlink($file);
        self::assertSame(['db.down', 'http.unhandled_exception'], array_column($errors, 'message'), 'newest first, only errors');
        self::assertSame('RuntimeException', $errors[1]['exception']);
        self::assertSame('X.php', $errors[1]['where']);
        self::assertStringNotContainsString('SECRET', (string) json_encode($errors));
        self::assertStringNotContainsString('a@b.c', (string) json_encode($errors));
        self::assertSame([], (new SystemStatus($this->db, $this->app->container()->get(\Redis::class), $this->app->container()->get(Heartbeat::class), $this->clock, $this->app->container()->get(Config::class), '/nonexistent/app.log', sys_get_temp_dir()))->errors());
        self::assertSame("1,5\u{00A0}КБ", SystemStatus::bytes(1536));
        self::assertSame("500\u{00A0}Б", SystemStatus::bytes(500));
    }
}
