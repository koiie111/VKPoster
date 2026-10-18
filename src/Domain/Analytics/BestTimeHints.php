<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Domain\Billing\Entitlements;
use App\Domain\Channel\ChannelRepository;
use App\Domain\Workspace\{WorkspaceContext,ChannelAccessRepository};
use App\Kernel\Database\Connection;
use App\Support\{Clock,DbTime};

/** Only latest engagement and publication time: no report, post text or subscriber queries. */
final class BestTimeHints
{
    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly Entitlements $plans,
        private readonly ChannelAccessRepository $access,
        private readonly ChannelRepository $channels,
    ) {
    }

    /**
     * @return array<string,mixed> Same thresholds/formula as the dashboard's heatmap.
     * @phpstan-impure
     */
    public function forWorkspace(WorkspaceContext $context): array
    {
        $zone = new \DateTimeZone($context->timezone);
        $today = $this->clock->now()->setTimezone($zone)->setTime(0, 0);
        $days = max(1, min(30, $this->plans->limit($context->workspaceId, 'analytics_days') ?? 365));
        $ids = array_map(
            static fn ($channel): int => $channel->id,
            $this->channels->all($context, $this->access->allowed($context, $context->userId))
        );
        $posts = [];
        if ($ids !== []) {
            $rows = $this->db->select(
                "SELECT p.sent_at,JSON_EXTRACT(s.metrics_json,'$.engagement') AS engagement
                 FROM publications p JOIN post_stats_snapshots s ON s.id=(
                    SELECT MAX(s2.id) FROM post_stats_snapshots s2
                    WHERE s2.workspace_id=p.workspace_id AND s2.publication_id=p.id)
                 WHERE p.workspace_id=? AND p.channel_id IN (".implode(',', array_fill(0, count($ids), '?')).")
                 AND p.status='sent' AND p.sent_at>=? AND p.sent_at<?",
                [$context->workspaceId,...$ids,DbTime::format($today->modify('-'.($days - 1).' days')),
                    DbTime::format($today->modify('+1 day'))],
            );
            foreach ($rows as $row) {
                $time = DbTime::parse($row['sent_at'])?->setTimezone($zone);
                $engagement = json_decode((string)($row['engagement'] ?? 'null'), true);
                if ($time !== null) {
                    $posts[] = ['weekday' => (int)$time->format('N'),'hour' => (int)$time->format('G'),
                        'metrics' => ['engagement' => PostMetrics::counter($engagement)]];
                }
            }
        }
        return ['best' => PostAnalytics::bestTime($posts),'timezone' => $context->timezone];
    }
}
