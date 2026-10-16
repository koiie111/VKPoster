<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Domain\Billing\Entitlements;
use App\Domain\Channel\{ChannelCredentials,ChannelSystem};
use App\Integrations\Social\Contracts\{ErrorKind,PlatformError};
use App\Kernel\Database\Connection;
use App\Kernel\Queue\Queue;
use App\Kernel\Security\RateLimiter;
use App\Support\{Clock,DbTime};

/** Durable snapshot planner, fenced read-only workers and plan-scoped retention. Never publishes. */
final class StatsCollector
{
    public const HOURS = [1,6,24,72,168,336,504,672];
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly Queue $queue, private readonly Entitlements $plans, private readonly ChannelSystem $channels, private readonly ChannelCredentials $credentials, private readonly StatsFetchers $fetchers, private readonly RateLimiter $limiter, private readonly \App\Integrations\Social\PlatformRegistry $platforms, private readonly \App\Kernel\Config $config)
    {
    }
    public function tick(): void
    {
        if (!$this->config->bool('analytics.collection_enabled')) {
            return;
        }
        $now = $this->clock->now();
        foreach (self::HOURS as $hours) {
            $rows = $this->db->select("SELECT p.id,p.workspace_id,p.channel_id FROM publications p JOIN channels c ON c.id=p.channel_id AND c.workspace_id=p.workspace_id WHERE p.status='sent' AND p.deleted_at IS NULL AND p.sent_at>=? AND p.sent_at<=? AND NOT EXISTS (SELECT 1 FROM analytics_tasks t WHERE t.workspace_id=p.workspace_id AND t.task_key=CONCAT('post:',p.id,':',?)) ORDER BY p.sent_at,p.id LIMIT 100", [DbTime::format($now->modify('-30 days')),DbTime::format($now->modify('-'.$hours.' hours')),$hours]);
            foreach ($rows as $r) {
                $this->plan((int)$r['workspace_id'], (int)$r['channel_id'], (int)$r['id'], 'post:'.$r['id'].':'.$hours);
            }
        }
        $day = $now->format('Y-m-d');
        foreach ($this->db->select("SELECT c.id,c.workspace_id FROM channels c WHERE c.status='active' AND NOT EXISTS (SELECT 1 FROM analytics_tasks t WHERE t.workspace_id=c.workspace_id AND t.task_key=CONCAT('channel:',c.id,':',?)) ORDER BY c.id LIMIT 200", [$day]) as $r) {
            $this->plan((int)$r['workspace_id'], (int)$r['id'], null, 'channel:'.$r['id'].':'.$day);
        }
        // Recover jobs lost after the queue exhausted its retry budget; completed tasks never run again.
        $this->db->transaction(function () use ($now): void {
            foreach ($this->db->select('SELECT id FROM analytics_tasks WHERE completed_at IS NULL AND enqueued_at<? AND (lease_until IS NULL OR lease_until<?) ORDER BY enqueued_at LIMIT 100 FOR UPDATE SKIP LOCKED', [DbTime::format($now->modify('-6 hours')),DbTime::format($now)]) as $r) {
                $this->queue->dispatch(new StatsJob((int)$r['id']));
                $this->db->execute('UPDATE analytics_tasks SET enqueued_at=? WHERE id=?', [DbTime::format($now),$r['id']]);
            }
        });
    }
    private function plan(int $workspace, int $channel, ?int $publication, string $key): void
    {
        $this->db->transaction(function () use ($workspace, $channel, $publication, $key): void {
            $now = DbTime::format($this->clock->now());
            $n = $this->db->execute('INSERT IGNORE INTO analytics_tasks(workspace_id,channel_id,publication_id,task_key,due_at,enqueued_at) VALUES(?,?,?,?,?,?)', [$workspace,$channel,$publication,$key,$now,$now]);
            if ($n > 0) {
                $r = $this->db->select('SELECT id FROM analytics_tasks WHERE workspace_id=? AND task_key=?', [$workspace,$key])[0];
                $this->queue->dispatch(new StatsJob((int)$r['id']));
            }
        });
    }
    public function run(int $id): void
    {
        $now = $this->clock->now();
        $lease = bin2hex(random_bytes(16));
        if ($this->db->execute('UPDATE analytics_tasks SET lease_token=?,lease_until=? WHERE id=? AND completed_at IS NULL AND (lease_until IS NULL OR lease_until<?)', [$lease,DbTime::format($now->modify('+5 minutes')),$id,DbTime::format($now)]) === 0) {
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
            // Dedicated token bucket is fail-closed; existing API clients retain their own gates too.
            if (!$this->limiter->attempt('stats-platform:'.$channel->platform->value, 60, 60, failClosed:true)->allowed) {
                throw new \RuntimeException('Statistics rate limited.');
            }
            if (!$this->limiter->attempt('stats:'.$channel->platform->value.':'.$channel->id, 30, 60, failClosed:true)->allowed) {
                throw new \RuntimeException('Statistics rate limited.');
            }
            $fetcher = $this->fetchers->for($channel->platform);
            $credential = $this->credentials->forChannel($channel);
            $metrics = null;
            $followers = null;
            if ($task['publication_id'] !== null) {
                $p = $this->db->select("SELECT external_post_id FROM publications WHERE id=? AND workspace_id=? AND channel_id=? AND status='sent' AND deleted_at IS NULL", [$task['publication_id'],$channel->workspaceId,$channel->id])[0] ?? null;
                if ($p === null || $p['external_post_id'] === null) {
                    $this->finish($id, $lease, 'publication_unavailable');
                    return;
                }
                $metrics = $fetcher->post($channel, $credential, (string)$p['external_post_id']);
            } else {
                $followers = $fetcher->followers($channel, $credential);
            }
            $this->db->transaction(function () use ($task, $id, $lease, $metrics, $followers): void {
                if ($this->db->execute('UPDATE analytics_tasks SET completed_at=?,error_code=NULL,lease_until=NULL WHERE id=? AND lease_token=? AND completed_at IS NULL', [DbTime::format($this->clock->now()),$id,$lease]) === 0) {
                    return;
                }
                $base = ['workspace_id' => $task['workspace_id'],'channel_id' => $task['channel_id'],'task_id' => $id,'observed_at' => DbTime::format($this->clock->now())];
                if ($metrics !== null) {
                    $this->db->table('post_stats_snapshots')->insert($base + ['publication_id' => $task['publication_id'],'metrics_json' => json_encode($metrics->toArray(), JSON_THROW_ON_ERROR)]);
                } else {
                    $this->db->table('channel_stats_snapshots')->insert($base + ['followers' => $followers]);
                }
            });
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
    private function release(int $id, string $lease, string $error): void
    {
        $this->db->execute('UPDATE analytics_tasks SET lease_until=NULL,error_code=? WHERE id=? AND lease_token=? AND completed_at IS NULL', [$error,$id,$lease]);
    }
    private function finish(int $id, string $lease, string $error): void
    {
        $this->db->execute('UPDATE analytics_tasks SET completed_at=?,error_code=?,lease_until=NULL WHERE id=? AND lease_token=? AND completed_at IS NULL', [DbTime::format($this->clock->now()),$error,$id,$lease]);
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
            $this->db->execute('DELETE FROM telegram_stats_reactions WHERE workspace_id=? AND event_date<?', [$workspace,$this->clock->now()->modify('-'.$this->days($workspace).' days')->getTimestamp()]);
            // Keep the task identity through the 30-day fetch horizon, even on the 7-day plan.
            $this->db->execute('DELETE FROM analytics_tasks WHERE workspace_id=? AND due_at<?', [$workspace,DbTime::format($this->clock->now()->modify('-'.max(35, $this->days($workspace)).' days'))]);
        }
    }
}
