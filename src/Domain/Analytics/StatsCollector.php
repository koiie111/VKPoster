<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Domain\Billing\Entitlements;
use App\Domain\Channel\{ChannelCredentials,ChannelSystem};
use App\Integrations\Social\Contracts\{ErrorKind,PlatformError};
use App\Kernel\Database\Connection;
use App\Kernel\Queue\{Queue,JobDeferred};
use App\Kernel\Security\RateLimiter;
use App\Support\{Clock,DbTime};

/** Durable snapshot planner, fenced read-only workers and plan-scoped retention. Never publishes. */
final class StatsCollector
{
    public const HOURS = [1,6,24,72,168,336,504,672];
    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly Queue $queue,
        private readonly Entitlements $plans,
        private readonly ChannelSystem $channels,
        private readonly ChannelCredentials $credentials,
        private readonly StatsFetchers $fetchers,
        private readonly RateLimiter $limiter,
        private readonly \App\Integrations\Social\PlatformRegistry $platforms,
        private readonly \App\Kernel\Config $config
    ) {
    }
    public function tick(): void
    {
        if (!$this->config->bool('analytics.collection_enabled')) {
            return;
        }
        $now = $this->clock->now();
        foreach (self::HOURS as $index => $hours) {
            $upper = self::HOURS[$index + 1] ?? 720;
            $rows = $this->db->select(
                "SELECT p.id,p.workspace_id,p.channel_id FROM publications p
                 JOIN channels c ON c.id=p.channel_id AND c.workspace_id=p.workspace_id
                 WHERE p.status='sent' AND p.deleted_at IS NULL AND p.sent_at>? AND p.sent_at<=?
                 AND NOT EXISTS (SELECT 1 FROM analytics_tasks t
                     WHERE t.workspace_id=p.workspace_id AND t.publication_id=p.id
                     AND CAST(SUBSTRING_INDEX(t.task_key, ':', -1) AS UNSIGNED)>=?)
                 ORDER BY p.sent_at,p.id LIMIT 100",
                [DbTime::format($now->modify('-'.$upper.' hours')),
                    DbTime::format($now->modify('-'.$hours.' hours')), $hours],
            );
            foreach ($rows as $r) {
                $this->plan((int)$r['workspace_id'], (int)$r['channel_id'], (int)$r['id'], 'post:'.$r['id'].':'.$hours);
            }
        }
        $day = $now->format('Y-m-d');
        foreach ($this->db->select(
            "SELECT c.id,c.workspace_id FROM channels c WHERE c.status='active' AND NOT EXISTS (SELECT 1 FROM analytics_tasks t WHERE
            t.workspace_id=c.workspace_id AND t.task_key=CONCAT('channel:',c.id,':',?)) ORDER BY c.id LIMIT 200",
            [$day]
        ) as $r) {
            $this->plan((int)$r['workspace_id'], (int)$r['id'], null, 'channel:'.$r['id'].':'.$day);
        }
        // Recover jobs lost after the queue exhausted its retry budget; completed tasks never run again.
        $this->db->transaction(function () use ($now): void {
            foreach ($this->db->select("SELECT t.id FROM analytics_tasks t WHERE t.completed_at IS NULL AND t.enqueued_at<?
                AND (t.lease_until IS NULL OR t.lease_until<?)
                AND NOT EXISTS (SELECT 1 FROM jobs j WHERE j.queue='analytics'
                    AND JSON_EXTRACT(j.payload_json,
                        '$.data.task_id')=t.id) ORDER BY enqueued_at LIMIT 100 FOR UPDATE SKIP LOCKED", [DbTime::format($now->modify('-6 hours')),DbTime::format($now)]) as $r) {
                $this->queue->dispatch(new StatsJob((int)$r['id']), queue: StatsJob::QUEUE);
                $this->db->execute('UPDATE analytics_tasks SET enqueued_at=? WHERE id=?', [DbTime::format($now),$r['id']]);
            }
        });
    }
    private function plan(int $workspace, int $channel, ?int $publication, string $key): void
    {
        $this->db->transaction(function () use ($workspace, $channel, $publication, $key): void {
            $now = DbTime::format($this->clock->now());
            $n = $this->db->execute(
                'INSERT IGNORE INTO analytics_tasks(workspace_id,channel_id,publication_id,task_key,due_at,enqueued_at) VALUES(?,?,?,?,?,?)',
                [$workspace,
                $channel,
                $publication,
                $key,
                $now,
                $now]
            );
            if ($n > 0) {
                $r = $this->db->select('SELECT id FROM analytics_tasks WHERE workspace_id=? AND task_key=?', [$workspace,$key])[0];
                $this->queue->dispatch(new StatsJob((int)$r['id']), queue: StatsJob::QUEUE);
            }
        });
    }
    public function run(int $id): void
    {
        $now = $this->clock->now();
        $lease = bin2hex(random_bytes(16));
        if ($this->db->execute(
            'UPDATE analytics_tasks SET lease_token=?,lease_until=? WHERE id=? AND completed_at IS NULL AND (lease_until IS NULL OR lease_until<?)',
            [$lease,
            DbTime::format($now->modify('+5 minutes')),
            $id,
            DbTime::format($now)]
        ) === 0) {
            return;
        }
        $task = $this->db->select('SELECT * FROM analytics_tasks WHERE id=?', [$id])[0];
        try {
            if (!$this->config->bool('analytics.collection_enabled')) {
                $this->finish($id, $lease, 'collection_disabled');
                return;
            }
            $channel = $this->channels->find((int)$task['channel_id']);
            if ($channel === null || $channel->workspaceId !== (int)$task['workspace_id'] || $channel->status->value !== 'active') {
                $this->finish($id, $lease, 'channel_unavailable');
                return;
            }
            if (!$this->platforms->isEnabled($channel->platform)) {
                $this->finish($id, $lease, 'platform_disabled');
                return;
            }
            if ($task['due_at'] < DbTime::format($now->modify('-'.$this->days($channel->workspaceId).' days'))) {
                $this->finish($id, $lease, 'expired');
                return;
            }
            // Capacity deferrals happen before credentials/provider calls and do not spend retry attempts.
            foreach (['stats-platform:'.$channel->platform->value => 60,
                'stats:'.$channel->platform->value.':'.$channel->id => 30] as $bucket => $limit) {
                try {
                    $gate = $this->limiter->attempt($bucket, $limit, 60, failClosed: true);
                } catch (\Throwable) {
                    throw new JobDeferred(60);
                }
                if (!$gate->allowed) {
                    throw new JobDeferred(max(1, $gate->retryAfter));
                }
            }
            $fetcher = $this->fetchers->for($channel->platform);
            $credential = $this->credentials->forChannel($channel);
            $metrics = null;
            $followers = null;
            if ($task['publication_id'] !== null) {
                $p = $this->db->select(
                    "SELECT external_post_id,sent_at FROM publications WHERE id=? AND workspace_id=? AND channel_id=? AND status='sent' AND deleted_at IS NULL",
                    [$task['publication_id'],
                    $channel->workspaceId,
                    $channel->id]
                )[0] ?? null;
                if ($p === null || $p['external_post_id'] === null) {
                    $this->finish($id, $lease, 'publication_unavailable');
                    return;
                }
                $sent = DbTime::parse($p['sent_at']) ?? throw new \LogicException('Missing publication date.');
                $elapsed = ($now->getTimestamp() - $sent->getTimestamp()) / 3600;
                $milestone = 0;
                foreach (self::HOURS as $hours) {
                    if ($elapsed >= $hours) {
                        $milestone = $hours;
                    }
                }
                if ($elapsed >= 720 || $task['task_key'] !== 'post:'.$task['publication_id'].':'.$milestone) {
                    $this->finish($id, $lease, 'milestone_missed');
                    return;
                }
                $metrics = $fetcher->post($channel, $credential, (string)$p['external_post_id']);
            } else {
                $followers = $fetcher->followers($channel, $credential);
            }
            $this->db->transaction(function () use ($task, $id, $lease, $metrics, $followers): void {
                if ($this->db->execute(
                    'UPDATE analytics_tasks SET completed_at=?,error_code=NULL,lease_until=NULL WHERE id=? AND lease_token=? AND completed_at IS NULL',
                    [DbTime::format($this->clock->now()),
                    $id,
                    $lease]
                ) === 0) {
                    return;
                }
                $base = ['workspace_id' => $task['workspace_id'],
                    'channel_id' => $task['channel_id'],
                    'task_id' => $id,
                    'observed_at' => DbTime::format($this->clock->now())];
                if ($metrics !== null) {
                    $this->saveMetrics($task, $metrics);
                } else {
                    $this->db->table('channel_stats_snapshots')->insert($base + ['followers' => $followers]);
                }
            });
        } catch (JobDeferred $e) {
            $this->release($id, $lease, null);
            throw $e;
        } catch (PartialMetricsFailure $e) {
            $this->db->transaction(function () use ($id, $lease, $task, $e): void {
                $owned = $this->db->select(
                    'SELECT id FROM analytics_tasks WHERE id=? AND lease_token=? AND completed_at IS NULL FOR UPDATE',
                    [$id,$lease],
                );
                if ($owned !== []) {
                    $this->saveMetrics($task, $e->metrics);
                    $this->release($id, $lease, 'temporary');
                }
            });
            throw new \RuntimeException('Statistics temporarily unavailable.');
        } catch (PlatformError $e) {
            if (in_array($e->kind, [ErrorKind::Auth,ErrorKind::Permanent], true)) {
                $this->finish($id, $lease, 'unavailable');
                return;
            }
            $this->release($id, $lease, $e->kind->value);
            throw new \RuntimeException('Statistics temporarily unavailable.');
        } catch (\Throwable) {
            $this->release($id, $lease, 'temporary');
            throw new \RuntimeException('Statistics temporarily unavailable.');
        }
    }
    /** @param array<string,mixed> $task Caller holds the current task lease inside a transaction. */
    private function saveMetrics(array $task, PostMetrics $metrics): void
    {
        $previous = $this->db->select('SELECT metrics_json FROM post_stats_snapshots WHERE task_id=?', [$task['id']]);
        $values = $metrics->toArray();
        if ($previous !== []) {
            $old = json_decode((string)$previous[0]['metrics_json'], true, 32, JSON_THROW_ON_ERROR);
            foreach ($metrics->statuses as $name => $status) {
                if ($status === 'transient_failure' && ($old[$name] ?? null) !== null) {
                    $values[$name] = $old[$name];
                }
            }
        }
        $json = json_encode($values + ['metric_statuses' => $metrics->statuses], JSON_THROW_ON_ERROR);
        $this->db->execute(
            'INSERT INTO post_stats_snapshots(workspace_id,channel_id,publication_id,task_id,observed_at,metrics_json)
             VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE observed_at=VALUES(observed_at),metrics_json=VALUES(metrics_json)',
            [$task['workspace_id'],$task['channel_id'],$task['publication_id'],$task['id'],
                DbTime::format($this->clock->now()),$json],
        );
    }
    private function release(int $id, string $lease, ?string $error): void
    {
        $this->db->execute(
            'UPDATE analytics_tasks SET lease_until=NULL,error_code=? WHERE id=? AND lease_token=? AND completed_at IS NULL',
            [$error,
            $id,
            $lease]
        );
    }
    private function finish(int $id, string $lease, string $error): void
    {
        $this->db->execute(
            'UPDATE analytics_tasks SET completed_at=?,error_code=?,lease_until=NULL WHERE id=? AND lease_token=? AND completed_at IS NULL',
            [DbTime::format($this->clock->now()),
            $error,
            $id,
            $lease]
        );
    }
    public function days(int $workspace): int
    {
        return max(1, $this->plans->limit($workspace, 'analytics_days') ?? 365);
    }
    public function prune(): void
    {
        foreach ($this->db->select('SELECT id FROM workspaces') as $w) {
            $workspace = (int)$w['id'];
            $before = DbTime::format($this->clock->now()->modify('-'.$this->days($workspace).' days'));
            foreach (['post_stats_snapshots','channel_stats_snapshots'] as $table) {
                $this->db->execute('DELETE FROM '.$table.' WHERE workspace_id=? AND observed_at<?', [$workspace,$before]);
            }
            $this->db->execute(
                'DELETE FROM telegram_stats_reactions WHERE workspace_id=? AND event_date<?',
                [$workspace,
                $this->clock->now()->modify('-'.$this->days($workspace).' days')->getTimestamp()]
            );
            // Keep the task identity through the 30-day fetch horizon, even on the 7-day plan.
            $this->db->execute(
                'DELETE FROM analytics_tasks WHERE workspace_id=? AND due_at<?',
                [$workspace,
                DbTime::format($this->clock->now()->modify('-'.max(
                    35,
                    $this->days($workspace)
                ).' days'))]
            );
        }
    }
}
