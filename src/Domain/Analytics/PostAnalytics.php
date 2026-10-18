<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Domain\Workspace\{WorkspaceContext,ChannelAccessRepository};
use App\Domain\Channel\ChannelRepository;
use App\Kernel\Database\Connection;
use App\Kernel\Exception\HttpException;
use App\Support\{Clock,DbTime};

/** Workspace/channel-scoped lifetime counters for publications sent in a selected local-date period. */
final class PostAnalytics
{
    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly StatsCollector $collector,
        private readonly ChannelAccessRepository $access,
        private readonly ChannelRepository $channels
    ) {
    }
    /** @param list<int>|null $postIds Guest capability further restricts the report.
     * @return array<string,mixed>
     */
    public function report(WorkspaceContext $ctx, string $from = '', string $to = '', ?string $channel = null, ?array $postIds = null): array
    {
        $zone = new \DateTimeZone($ctx->timezone);
        $today = $this->clock->now()->setTimezone($zone);
        $days = $this->collector->days($ctx->workspaceId);
        $earliest = $today->modify('-'.($days - 1).' days')->setTime(0, 0);
        $start = $from === '' ? $today->modify('-'.(min(30, $days) - 1).' days')->setTime(0, 0) : $this->date($from, $zone);
        $end = $to === '' ? $today->setTime(0, 0) : $this->date($to, $zone);
        if ($end < $start || $end > $today || $start < $earliest) {
            throw new HttpException(422, 'Выберите период в пределах тарифа: '.$days.' дней.');
        }
        $allowed = $this->access->allowed($ctx, $ctx->userId);
        $channels = $this->channels->all($ctx, $allowed);
        $channelIds = array_map(static fn ($c): int => $c->id, $channels);
        if ($channel !== null && $channel !== '') {
            $found = array_values(array_filter($channels, static fn ($c): bool => $c->publicId === $channel));
            if ($found === []) {
                throw new HttpException(404, 'Not found');
            }
            $channelIds = [$found[0]->id];
        }
        $rows = [];
        $followers = [];
        if ($channelIds !== [] && $postIds !== []) {
            $marks = implode(',', array_fill(0, count($channelIds), '?'));
            $where = 'p.workspace_id=? AND p.channel_id IN ('.$marks.") AND p.status='sent' AND p.sent_at>=? AND p.sent_at<?";
            $params = [$ctx->workspaceId,...$channelIds,DbTime::format($start),DbTime::format($end->modify('+1 day'))];
            if ($postIds !== null) {
                $where .= ' AND p.post_id IN ('.implode(',', array_fill(0, count($postIds), '?')).')';
                $params = [...$params,...$postIds];
            }
            $rows = $this->db->select(
                'SELECT p.id,p.public_id,p.post_id,p.channel_id,p.sent_at,c.title,c.platform,LEFT(po.base_text,201) AS
                text,s.metrics_json,s.observed_at FROM publications p JOIN channels c ON c.id=p.channel_id AND
                c.workspace_id=p.workspace_id JOIN posts po ON po.id=p.post_id AND po.workspace_id=p.workspace_id LEFT JOIN
                post_stats_snapshots s ON s.id=(SELECT MAX(s2.id) FROM post_stats_snapshots s2 WHERE s2.workspace_id=p.workspace_id AND
                s2.publication_id=p.id) WHERE '.$where.' ORDER BY p.sent_at DESC,p.id DESC',
                $params
            );
            // Guest reports never widen to channel subscriber history outside the post capability.
            if ($postIds === null) {
                $followers = $this->db->select(
                    'SELECT s.channel_id,c.title,s.observed_at,s.followers FROM channel_stats_snapshots s JOIN channels c ON
                    c.id=s.channel_id AND c.workspace_id=s.workspace_id WHERE s.workspace_id=? AND s.channel_id IN ('.$marks.') AND s.observed_at>=? AND s.observed_at<? ORDER BY s.observed_at',
                    [$ctx->workspaceId,
                    ...$channelIds,
                    DbTime::format($start),
                    DbTime::format($end->modify('+1 day'))]
                );
            }
        }
        $posts = [];
        $daily = [];
        $byChannel = [];
        $platforms = [];
        foreach ($rows as $r) {
            $m = $r['metrics_json'] === null ? [] : json_decode((string)$r['metrics_json'], true, 32, JSON_THROW_ON_ERROR);
            $metrics = new PostMetrics(
                PostMetrics::counter($m['views'] ?? null),
                PostMetrics::counter($m['reach'] ?? null),
                PostMetrics::counter($m['engagement'] ?? null),
                PostMetrics::counter($m['likes'] ?? null),
                PostMetrics::counter($m['comments'] ?? null),
                PostMetrics::counter($m['shares'] ?? null)
            );
            $time = (DbTime::parse($r['sent_at']) ?? throw new \LogicException('Missing publication date.'))->setTimezone($zone);
            $excerpt = ReportExcerpt::fromText((string) $r['text']);
            // Keep the existing dashboard/CSV text bound; PDF gets an explicit word-safe excerpt.
            $r['text'] = mb_substr((string) $r['text'], 0, 200);
            $post = $r + ['excerpt' => $excerpt, 'metrics' => $metrics->toArray(),
                'er' => $metrics->er(),
                'local_date' => $time->format('Y-m-d'),
                'weekday' => (int)$time->format('N'),
                'hour' => (int)$time->format('G')];
            $posts[] = $post;
            $daily[$time->format('Y-m-d')][] = $post;
            $byChannel[(string)$r['channel_id']][] = $post;
            $platforms[(string)$r['platform']][] = $post;
        }
        ksort($daily);
        $top = $posts;
        usort($top, static fn (array $a, array $b): int => ($b['metrics']['engagement'] ?? -1) <=> ($a['metrics']['engagement'] ?? -1));
        $top = array_values(array_filter($top, static fn (array $p): bool => $p['metrics']['engagement'] !== null));
        return ['from' => $start->format('Y-m-d'),
            'to' => $end->format('Y-m-d'),
            'days' => $days,
            'timezone' => $ctx->timezone,
            'channels' => $channels,
            'channel' => $channel ?? '',
            'posts' => $posts,
            'totals' => self::aggregate($posts),
            'daily' => array_map(
                self::aggregate(...),
                $daily
            ),
            'by_channel' => array_map(
                static fn (array $rows): array => self::aggregate($rows) + ['label' => $rows[0]['title'].' · '.$rows[0]['platform']],
                $byChannel
            ),
            'platforms' => array_map(
                self::aggregate(...),
                $platforms
            ),
            'top' => array_slice(
                $top,
                0,
                10
            ),
            'best' => self::bestTime($posts),
            'followers' => $followers];
    }
    private function date(string $date, \DateTimeZone $zone): \DateTimeImmutable
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, $zone);
        if ($d === false || $d->format('Y-m-d') !== $date) {
            throw new HttpException(422, 'Проверьте даты периода.');
        }
        return $d;
    }
    /** @param list<array<string,mixed>> $posts
     * @return array<string,mixed>
     */
    public static function aggregate(array $posts): array
    {
        $out = ['publications' => count($posts),'coverage' => []];
        foreach (['views','reach','engagement'] as $key) {
            $known = array_filter(array_column(array_column($posts, 'metrics'), $key), static fn ($v): bool => $v !== null);
            $out['coverage'][$key] = count($known);
            $out[$key] = count($posts) > 0 && count($known) === count($posts) ? array_sum($known) : null;
        }
        // Ratio of sums of matched reach and engagement, never the mean of individual percentages.
        $out['er'] = $out['reach'] !== null && $out['reach'] > 0 && $out['engagement'] !== null ? round(
            100 * $out['engagement'] / $out['reach'],
            2
        ) : null;
        return $out;
    }
    /** @param list<array<string,mixed>> $posts
     * @return array<string,mixed>
     */
    public static function bestTime(array $posts): array
    {
        $cells = [];
        $known = 0;
        foreach ($posts as $p) {
            if ($p['metrics']['engagement'] === null) {
                continue;
            }
            ++$known;
            $key = $p['weekday'].'-'.$p['hour'];
            $cells[$key][] = $p['metrics']['engagement'];
        }
        $result = [];
        for ($d = 1;$d <= 7;++$d) {
            for ($h = 0;$h < 24;++$h) {
                $values = $cells[$d.'-'.$h] ?? [];
                $n = count($values);
                $result[] = ['day' => $d,'hour' => $h,'count' => $n,'average' => $n >= 5 && $known >= 20 ? round(array_sum($values) / $n, 2) : null];
            }
        }
        $rank = array_values(array_filter($result, static fn (array $c): bool => $c['average'] !== null));
        usort($rank, static fn (array $a, array $b): int => $b['average'] <=> $a['average']);
        return ['count' => $known,'cells' => $result,'recommendation' => $rank[0] ?? null];
    }
}
