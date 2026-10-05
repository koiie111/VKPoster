<?php

declare(strict_types=1);

namespace App\Domain\Admin;

use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;

/**
 * Numbers for the admin overview: people, plans, publishing of the last day, queues and channels by network.
 * The full business dashboard (revenue, funnels, cohorts) is stage 20; this is what the owner needs on day one.
 */
final class AdminStats
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    /**
     * @return array{users: int, users_week: int, workspaces: int, plans: list<array{plan: string, status: string, count: int}>}
     */
    public function accounts(): array
    {
        $week = DbTime::format($this->clock->now()->modify('-7 days'));

        return [
            'users' => $this->scalar('SELECT COUNT(*) AS c FROM users'),
            'users_week' => $this->scalar('SELECT COUNT(*) AS c FROM users WHERE created_at >= ?', [$week]),
            'workspaces' => $this->scalar('SELECT COUNT(*) AS c FROM workspaces'),
            'plans' => array_map(static fn (array $r): array => ['plan' => (string) $r['plan'], 'status' => (string) $r['status'], 'count' => (int) $r['c']], $this->db->select(
                'SELECT p.name AS plan, s.status AS status, COUNT(*) AS c FROM subscriptions s JOIN plans p ON p.id = s.plan_id GROUP BY p.id, p.name, p.sort, s.status ORDER BY p.sort, s.status',
            )),
        ];
    }

    /**
     * Publications that fell due in the last 24 hours, by platform and status.
     *
     * @return array<string, array<string, int>> platform => status => count
     */
    public function publications(): array
    {
        $since = DbTime::format($this->clock->now()->modify('-24 hours'));
        $result = [];
        foreach ($this->db->select(
            'SELECT c.platform AS platform, p.status AS status, COUNT(*) AS c FROM publications p JOIN channels c ON c.id = p.channel_id WHERE p.due_at >= ? GROUP BY c.platform, p.status',
            [$since],
        ) as $row) {
            $result[(string) $row['platform']][(string) $row['status']] = (int) $row['c'];
        }

        return $result;
    }

    /**
     * @return array{queues: array<string, int>, failed: int}
     */
    public function queues(): array
    {
        $queues = [];
        foreach ($this->db->select('SELECT queue, COUNT(*) AS c FROM jobs GROUP BY queue ORDER BY queue') as $row) {
            $queues[(string) $row['queue']] = (int) $row['c'];
        }

        return ['queues' => $queues, 'failed' => $this->scalar('SELECT COUNT(*) AS c FROM failed_jobs')];
    }

    /**
     * Channel counts by platform and status.
     *
     * @return array<string, array<string, int>>
     */
    public function channels(): array
    {
        $result = [];
        foreach ($this->db->select('SELECT platform, status, COUNT(*) AS c FROM channels GROUP BY platform, status ORDER BY platform') as $row) {
            $result[(string) $row['platform']][(string) $row['status']] = (int) $row['c'];
        }

        return $result;
    }

    /**
     * Channels that need attention (broken or errored), newest problem first.
     *
     * @return list<array<string, mixed>>
     */
    public function brokenChannels(int $limit = 50): array
    {
        return array_map(static function (array $r): array {
            $r['last_health_at'] = DbTime::parse($r['last_health_at']);

            return $r;
        }, $this->db->select(
            'SELECT c.platform, c.title, c.status, c.last_error, c.last_health_at, w.public_id AS workspace_public_id, w.name AS workspace_name '
            . 'FROM channels c JOIN workspaces w ON w.id = c.workspace_id WHERE c.status IN (\'error\', \'revoked\') ORDER BY c.updated_at DESC LIMIT ' . max(1, $limit),
        ));
    }

    /**
     * @param list<int|string> $bindings
     */
    private function scalar(string $sql, array $bindings = []): int
    {
        return (int) ($this->db->select($sql, $bindings)[0]['c'] ?? 0);
    }
}
