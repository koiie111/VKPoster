<?php

declare(strict_types=1);

namespace App\Domain\Automation\Rss;

use App\Domain\Workspace\WorkspaceContext;
use App\Domain\Workspace\WorkspaceScopedRepository;
use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;
use DateTimeImmutable;
use Symfony\Component\Uid\Ulid;

/**
 * RSS feeds of a workspace, their channels and the entries already turned into posts.
 */
final class RssRepository extends WorkspaceScopedRepository
{
    public function __construct(Connection $db, private readonly Clock $clock)
    {
        parent::__construct($db);
    }

    /**
     * @return list<RssFeed>
     */
    public function all(WorkspaceContext $context): array
    {
        return array_map($this->hydrate(...), $this->scoped($context, 'rss_feeds')->orderBy('id')->limit(200)->get());
    }

    public function find(WorkspaceContext $context, string $publicId): ?RssFeed
    {
        $row = $this->scoped($context, 'rss_feeds')->where('public_id', '=', strtoupper($publicId))->first();

        return $row === null ? null : $this->hydrate($row);
    }

    public function findById(WorkspaceContext $context, int $id): ?RssFeed
    {
        $row = $this->scoped($context, 'rss_feeds')->where('id', '=', $id)->first();

        return $row === null ? null : $this->hydrate($row);
    }

    public function count(WorkspaceContext $context): int
    {
        return $this->scoped($context, 'rss_feeds')->count();
    }

    /**
     * @param list<int> $channelIds
     */
    public function create(WorkspaceContext $context, string $name, string $url, string $template, string $mode, ?int $queueId, int $intervalMinutes, array $channelIds): RssFeed
    {
        $now = DbTime::format($this->clock->now());
        $publicId = (string) new Ulid();
        $this->db->transaction(function () use ($context, $name, $url, $template, $mode, $queueId, $intervalMinutes, $channelIds, $now, $publicId): void {
            $id = (int) $this->db->table('rss_feeds')->insert([
                'public_id' => $publicId, 'workspace_id' => $context->workspaceId, 'name' => mb_substr($name, 0, 100), 'url' => $url, 'template' => $template,
                'mode' => $mode, 'queue_id' => $queueId, 'interval_minutes' => $intervalMinutes, 'enabled' => 1, 'next_check_at' => $now,
                'created_by' => $context->userId, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->writeChannels($id, $channelIds);
        });

        return $this->find($context, $publicId) ?? throw new \LogicException('The inserted feed disappeared.');
    }

    /**
     * @param list<int> $channelIds
     */
    public function update(WorkspaceContext $context, RssFeed $feed, string $name, string $url, string $template, string $mode, ?int $queueId, int $intervalMinutes, array $channelIds): void
    {
        $this->db->transaction(function () use ($context, $feed, $name, $url, $template, $mode, $queueId, $intervalMinutes, $channelIds): void {
            $changes = ['name' => mb_substr($name, 0, 100), 'url' => $url, 'template' => $template, 'mode' => $mode, 'queue_id' => $queueId, 'interval_minutes' => $intervalMinutes, 'updated_at' => DbTime::format($this->clock->now())];
            if ($url !== $feed->url) {
                // Another address is another feed: forget what the old one said about itself.
                $changes += ['etag' => null, 'last_modified' => null, 'last_error' => null, 'error_count' => 0, 'next_check_at' => DbTime::format($this->clock->now())];
            }
            $this->scoped($context, 'rss_feeds')->where('id', '=', $feed->id)->update($changes);
            $this->db->table('rss_feed_channels')->where('feed_id', '=', $feed->id)->delete();
            $this->writeChannels($feed->id, $channelIds);
        });
    }

    public function setEnabled(WorkspaceContext $context, RssFeed $feed, bool $enabled): void
    {
        $this->scoped($context, 'rss_feeds')->where('id', '=', $feed->id)->update(['enabled' => $enabled ? 1 : 0, 'next_check_at' => $enabled ? DbTime::format($this->clock->now()) : null, 'updated_at' => DbTime::format($this->clock->now())]);
    }

    public function delete(WorkspaceContext $context, RssFeed $feed): void
    {
        $this->scoped($context, 'rss_feeds')->where('id', '=', $feed->id)->delete();
    }

    /**
     * Remember an entry. Returns false when it was seen before: the unique key lets one caller win, so a feed read twice at once
     * can not make two posts.
     */
    public function claimEntry(WorkspaceContext $context, RssFeed $feed, string $key): bool
    {
        if ($feed->workspaceId !== $context->workspaceId) {
            throw new \LogicException('A feed of another workspace.');
        }

        return $this->db->execute('INSERT IGNORE INTO rss_items (feed_id, guid_hash, created_at) VALUES (?, ?, ?)', [$feed->id, $key, DbTime::format($this->clock->now())]) === 1;
    }

    public function attachPost(WorkspaceContext $context, RssFeed $feed, string $key, int $postId): void
    {
        if ($feed->workspaceId === $context->workspaceId) {
            $this->db->execute('UPDATE rss_items SET post_id = ? WHERE feed_id = ? AND guid_hash = ?', [$postId, $feed->id, $key]);
        }
    }

    public function entryCount(WorkspaceContext $context, RssFeed $feed): int
    {
        return $feed->workspaceId === $context->workspaceId ? $this->db->table('rss_items')->where('feed_id', '=', $feed->id)->count() : 0;
    }

    /**
     * Store the outcome of a reading and decide when the next one is.
     */
    public function recordCheck(WorkspaceContext $context, RssFeed $feed, ?string $etag, ?string $lastModified, ?string $error, DateTimeImmutable $nextCheckAt): void
    {
        $this->scoped($context, 'rss_feeds')->where('id', '=', $feed->id)->update([
            'etag' => $error === null ? $etag : $feed->etag, 'last_modified' => $error === null ? $lastModified : $feed->lastModified,
            'last_checked_at' => DbTime::format($this->clock->now()), 'next_check_at' => DbTime::format($nextCheckAt),
            'last_error' => $error === null ? null : mb_substr($error, 0, 255), 'error_count' => $error === null ? 0 : $feed->errorCount + 1,
        ]);
    }

    /**
     * @param list<int> $channelIds
     */
    private function writeChannels(int $feedId, array $channelIds): void
    {
        foreach (array_unique($channelIds) as $channelId) {
            $this->db->table('rss_feed_channels')->insert(['feed_id' => $feedId, 'channel_id' => $channelId]);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): RssFeed
    {
        $channels = array_map(static fn (array $c): int => (int) $c['channel_id'], $this->db->table('rss_feed_channels')->where('feed_id', '=', (int) $row['id'])->get());

        return new RssFeed(
            (int) $row['id'],
            (string) $row['public_id'],
            (int) $row['workspace_id'],
            (string) $row['name'],
            (string) $row['url'],
            (string) $row['template'],
            (string) $row['mode'],
            isset($row['queue_id']) ? (int) $row['queue_id'] : null,
            (int) $row['interval_minutes'],
            (int) $row['enabled'] === 1,
            isset($row['etag']) ? (string) $row['etag'] : null,
            isset($row['last_modified']) ? (string) $row['last_modified'] : null,
            DbTime::parse($row['last_checked_at'] ?? null),
            DbTime::parse($row['next_check_at'] ?? null),
            isset($row['last_error']) ? (string) $row['last_error'] : null,
            (int) $row['error_count'],
            $channels,
            isset($row['created_by']) ? (int) $row['created_by'] : null,
        );
    }
}
