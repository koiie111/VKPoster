<?php

declare(strict_types=1);

namespace App\Domain\Post;

use App\Domain\Workspace\WorkspaceContext;
use App\Domain\Workspace\WorkspaceScopedRepository;
use App\Integrations\Social\Contracts\Platform;
use App\Support\DbTime;
use DateTimeImmutable;

/**
 * Read model for the calendar and the workspace home: publications in a period, joined with their posts, variants and authors,
 * filtered by channel, state and author, and limited to the channels the member may see.
 */
final class CalendarRepository extends WorkspaceScopedRepository
{
    /** Calendar filter groups => the publication states they stand for. */
    public const STATE_GROUPS = [
        'scheduled' => [PublicationStatus::Queued, PublicationStatus::Sending],
        'published' => [PublicationStatus::Sent],
        'failed' => [PublicationStatus::Failed, PublicationStatus::Unknown],
    ];

    /**
     * @param list<string> $groups keys of `STATE_GROUPS`; empty = all
     * @param list<int>|null $allowedChannelIds null = every channel
     * @return list<CalendarItem> in time order
     */
    public function items(WorkspaceContext $context, DateTimeImmutable $from, DateTimeImmutable $to, ?int $channelId, array $groups, ?int $authorId, ?array $allowedChannelIds, int $limit = 1000): array
    {
        if ($allowedChannelIds === []) {
            return [];
        }
        $sql = 'SELECT p.public_id AS pub_id, p.status AS pub_status, p.due_at, v.channel_name, v.platform, v.text AS variant_text,
                       po.public_id AS post_id, po.base_text, po.media_ids_json, po.status AS post_status, u.name AS author_name
                FROM publications p
                JOIN posts po ON po.id = p.post_id
                JOIN post_variants v ON v.id = p.variant_id
                LEFT JOIN users u ON u.id = po.author_id
                WHERE p.workspace_id = ? AND p.due_at >= ? AND p.due_at < ? AND p.status <> \'cancelled\'';
        $bindings = [$context->workspaceId, DbTime::format($from), DbTime::format($to)];
        if ($channelId !== null) {
            $sql .= ' AND p.channel_id = ?';
            $bindings[] = $channelId;
        }
        if ($authorId !== null) {
            $sql .= ' AND po.author_id = ?';
            $bindings[] = $authorId;
        }
        if ($allowedChannelIds !== null) {
            $sql .= ' AND p.channel_id IN (' . implode(',', array_fill(0, count($allowedChannelIds), '?')) . ')';
            array_push($bindings, ...$allowedChannelIds);
        }
        $states = [];
        foreach ($groups as $group) {
            foreach (self::STATE_GROUPS[$group] ?? [] as $state) {
                $states[] = $state->value;
            }
        }
        if ($states !== []) {
            $sql .= ' AND p.status IN (' . implode(',', array_fill(0, count($states), '?')) . ')';
            array_push($bindings, ...$states);
        }
        $sql .= ' ORDER BY p.due_at, p.id LIMIT ' . max(1, $limit);

        return array_map(self::item(...), $this->db->select($sql, $bindings));
    }

    /**
     * Publications that need a person: failed ones and ones whose outcome is unknown, newest first.
     *
     * @param list<int>|null $allowedChannelIds
     * @return list<CalendarItem>
     */
    public function needingAttention(WorkspaceContext $context, ?array $allowedChannelIds, int $limit = 5): array
    {
        if ($allowedChannelIds === []) {
            return [];
        }
        $from = new DateTimeImmutable('2000-01-01', new \DateTimeZone('UTC'));
        $items = $this->items($context, $from, new DateTimeImmutable('2100-01-01', new \DateTimeZone('UTC')), null, ['failed'], null, $allowedChannelIds, 500);

        return array_slice(array_reverse($items), 0, $limit);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function item(array $row): CalendarItem
    {
        $post = new Post(0, (string) $row['post_id'], 0, null, PostStatus::from((string) $row['post_status']), is_string($row['variant_text'] ?? null) ? $row['variant_text'] : (string) $row['base_text'], [], new PostOptions(), false, null, 'UTC', null, new DateTimeImmutable('@0'), new DateTimeImmutable('@0'));
        $media = is_string($row['media_ids_json'] ?? null) ? json_decode($row['media_ids_json'], true) : [];
        $title = $post->title(60);
        if ($title === 'Пустой пост' && is_array($media) && $media !== []) {
            $title = 'Пост без текста';
        }

        return new CalendarItem(
            (string) $row['post_id'],
            (string) $row['pub_id'],
            Platform::from((string) $row['platform']),
            (string) $row['channel_name'],
            DbTime::parse($row['due_at']) ?? new DateTimeImmutable('@0'),
            PublicationStatus::from((string) $row['pub_status']),
            $title,
            is_string($row['author_name'] ?? null) ? $row['author_name'] : null,
            PostStatus::from((string) $row['post_status']),
        );
    }
}
