<?php

declare(strict_types=1);

namespace App\Domain\Post;

use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;
use DateTimeImmutable;
use DateTimeZone;

/** Conservative local accounting for YouTube's project-wide videos.insert bucket. */
final class VideoUploadQuota
{
    private const YOUTUBE_ZONE = 'America/Los_Angeles';

    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    public function day(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone(self::YOUTUBE_ZONE))->format('Y-m-d');
    }

    public function nextReset(): DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new DateTimeZone(self::YOUTUBE_ZONE))->modify('tomorrow')->setTime(0, 0)->setTimezone(new DateTimeZone('UTC'));
    }

    public function secondsUntilReset(): int
    {
        return max(60, $this->nextReset()->getTimestamp() - $this->clock->now()->getTimestamp());
    }

    /** Reserve before each videos.insert call. Google can charge even for failed requests. */
    public function reserveYouTube(string $idempotencyKey, int $limit): bool
    {
        return $this->db->transaction(function () use ($idempotencyKey, $limit): bool {
            $publication = $this->db->select('SELECT id FROM publications WHERE idempotency_key=?', [$idempotencyKey])[0] ?? null;
            if ($publication === null) {
                throw new \LogicException('Publication for YouTube quota is missing.');
            }
            $id = (int) $publication['id'];
            $day = $this->day($this->clock->now());
            $this->db->execute('INSERT IGNORE INTO video_upload_quota_days (platform,quota_day,used) VALUES (?,?,0)', ['youtube', $day]);
            $used = (int) ($this->db->select('SELECT used FROM video_upload_quota_days WHERE platform=? AND quota_day=? FOR UPDATE', ['youtube', $day])[0]['used'] ?? 0);
            if ($used >= $limit) {
                return false;
            }
            $this->db->execute('UPDATE video_upload_quota_days SET used=used+1 WHERE platform=? AND quota_day=?', ['youtube', $day]);
            $this->db->execute('INSERT INTO video_upload_quota_reservations (publication_id,platform,quota_day,created_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE quota_day=VALUES(quota_day),created_at=VALUES(created_at)', [$id, 'youtube', $day, DbTime::format($this->clock->now())]);
            return true;
        });
    }

    /** Includes unsent plans and already consumed calls across all workspaces in this Google project. */
    public function remainingYouTube(DateTimeImmutable $at, int $limit, ?int $exceptPostId = null): int
    {
        $day = $this->day($at);
        $start = new DateTimeImmutable($day . ' 00:00:00', new DateTimeZone(self::YOUTUBE_ZONE));
        $end = $start->modify('+1 day');
        $used = (int) ($this->db->select('SELECT used FROM video_upload_quota_days WHERE platform=? AND quota_day=?', ['youtube', $day])[0]['used'] ?? 0);
        $planned = (int) ($this->db->select("SELECT COUNT(*) AS c FROM publications p
            JOIN channels c ON c.id=p.channel_id AND c.workspace_id=p.workspace_id
            LEFT JOIN video_upload_quota_reservations r ON r.publication_id=p.id AND r.quota_day=?
            WHERE c.platform='youtube' AND p.status IN ('queued','sending','processing','sent','unknown')
            AND p.due_at>=? AND p.due_at<? AND p.post_id<>? AND r.publication_id IS NULL", [$day, DbTime::format($start), DbTime::format($end), $exceptPostId ?? 0])[0]['c'] ?? 0);
        return max(0, $limit - $used - $planned);
    }
}
