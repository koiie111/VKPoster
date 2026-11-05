<?php

declare(strict_types=1);

namespace App\Domain\Post;

use App\Kernel\Database\Connection;
use App\Kernel\Security\Crypto;
use App\Support\Clock;
use App\Support\DbTime;
use DateTimeImmutable;
use LogicException;

/** Durable network upload state keyed by the publication's stable idempotency key. */
final class VideoProviderSessions
{
    public function __construct(private readonly Connection $db, private readonly Crypto $crypto, private readonly Clock $clock)
    {
    }

    /** @return array{publication_id: int, workspace_id: int, platform: string, remote_id: ?string, upload_url: ?string, confirmed_bytes: int, phase: string, poll_after: ?DateTimeImmutable, deadline_at: DateTimeImmutable}|null */
    public function find(string $idempotencyKey): ?array
    {
        $row = $this->db->select('SELECT s.* FROM video_provider_sessions s JOIN publications p ON p.id=s.publication_id AND p.workspace_id=s.workspace_id WHERE p.idempotency_key=?', [$idempotencyKey])[0] ?? null;
        if ($row === null) {
            return null;
        }
        $url = is_string($row['upload_url_enc'] ?? null) && $row['upload_url_enc'] !== '' ? $this->crypto->decrypt($row['upload_url_enc'], 'video-upload-url') : null;
        return [
            'publication_id' => (int) $row['publication_id'],
            'workspace_id' => (int) $row['workspace_id'],
            'platform' => (string) $row['platform'],
            'remote_id' => is_string($row['remote_id'] ?? null) ? $row['remote_id'] : null,
            'upload_url' => $url,
            'confirmed_bytes' => (int) $row['confirmed_bytes'],
            'phase' => (string) $row['phase'],
            'poll_after' => DbTime::parse($row['poll_after'] ?? null),
            'deadline_at' => DbTime::parse($row['deadline_at'] ?? null) ?? throw new LogicException('Invalid video session deadline.'),
        ];
    }

    /** Save the external operation before sending any video bytes. */
    public function begin(string $idempotencyKey, string $platform, ?string $remoteId, ?string $uploadUrl, int $ttlSeconds = 14400): void
    {
        $publication = $this->db->select('SELECT id,workspace_id FROM publications WHERE idempotency_key=?', [$idempotencyKey])[0] ?? null;
        if ($publication === null) {
            throw new LogicException('Publication for video session is missing.');
        }
        $now = DbTime::format($this->clock->now());
        $this->db->execute('INSERT INTO video_provider_sessions (publication_id,workspace_id,platform,remote_id,upload_url_enc,confirmed_bytes,phase,poll_after,deadline_at,created_at,updated_at)
            VALUES (?,?,?,?,?,0,?,NULL,?,?,?)', [
                $publication['id'], $publication['workspace_id'], $platform, $remoteId,
                $uploadUrl === null ? null : $this->crypto->encrypt($uploadUrl, 'video-upload-url'),
                $uploadUrl === null ? 'processing' : 'uploading',
                DbTime::format($this->clock->now()->modify(sprintf('+%d seconds', $ttlSeconds))), $now, $now,
            ]);
    }

    public function confirm(string $idempotencyKey, int $bytes): void
    {
        $this->db->execute(
            'UPDATE video_provider_sessions s JOIN publications p ON p.id=s.publication_id AND p.workspace_id=s.workspace_id
            SET s.confirmed_bytes=GREATEST(s.confirmed_bytes,?),s.updated_at=? WHERE p.idempotency_key=? AND s.phase=?',
            [$bytes, DbTime::format($this->clock->now()), $idempotencyKey, 'uploading']
        );
    }

    public function processing(string $idempotencyKey, ?string $remoteId = null): void
    {
        $this->db->execute(
            'UPDATE video_provider_sessions s JOIN publications p ON p.id=s.publication_id AND p.workspace_id=s.workspace_id
            SET s.phase=?,s.remote_id=COALESCE(?,s.remote_id),s.poll_after=?,s.updated_at=? WHERE p.idempotency_key=?',
            ['processing', $remoteId, DbTime::format($this->clock->now()), DbTime::format($this->clock->now()), $idempotencyKey]
        );
    }

    public function pollLater(string $idempotencyKey, int $seconds): void
    {
        $this->db->execute(
            'UPDATE video_provider_sessions s JOIN publications p ON p.id=s.publication_id AND p.workspace_id=s.workspace_id
            SET s.poll_after=?,s.updated_at=? WHERE p.idempotency_key=? AND s.phase=?',
            [DbTime::format($this->clock->now()->modify(sprintf('+%d seconds', $seconds))), DbTime::format($this->clock->now()), $idempotencyKey, 'processing']
        );
    }

    /** @return list<int> Network processing checks whose job is due or was lost. */
    public function dueProcessing(int $limit = 200): array
    {
        $rows = $this->db->select(
            'SELECT s.publication_id FROM video_provider_sessions s JOIN publications p ON p.id=s.publication_id AND p.workspace_id=s.workspace_id
            WHERE p.status=? AND s.phase=? AND (s.poll_after IS NULL OR s.poll_after <= ?) ORDER BY s.poll_after,s.publication_id LIMIT ' . max(1, $limit),
            [PublicationStatus::Processing->value, 'processing', DbTime::format($this->clock->now())]
        );
        return array_map(static fn (array $row): int => (int) $row['publication_id'], $rows);
    }

    /** Give an enqueued job five minutes to run before a replacement is created. */
    public function markPollEnqueued(int $publicationId): void
    {
        $this->db->execute(
            'UPDATE video_provider_sessions SET poll_after=?,updated_at=? WHERE publication_id=? AND phase=?',
            [DbTime::format($this->clock->now()->modify('+300 seconds')), DbTime::format($this->clock->now()), $publicationId, 'processing']
        );
    }
}
