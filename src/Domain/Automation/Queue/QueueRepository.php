<?php

declare(strict_types=1);

namespace App\Domain\Automation\Queue;

use App\Domain\Workspace\WorkspaceContext;
use App\Domain\Workspace\WorkspaceScopedRepository;
use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;
use Symfony\Component\Uid\Ulid;

/**
 * Queues of a workspace with their slots, channels and waiting posts.
 */
final class QueueRepository extends WorkspaceScopedRepository
{
    public function __construct(Connection $db, private readonly Clock $clock)
    {
        parent::__construct($db);
    }

    /**
     * @return list<PostQueue> oldest first
     */
    public function all(WorkspaceContext $context): array
    {
        return array_map($this->hydrate(...), $this->scoped($context, 'queues')->orderBy('id')->limit(200)->get());
    }

    public function find(WorkspaceContext $context, string $publicId): ?PostQueue
    {
        $row = $this->scoped($context, 'queues')->where('public_id', '=', strtoupper($publicId))->first();

        return $row === null ? null : $this->hydrate($row);
    }

    public function findById(WorkspaceContext $context, int $id): ?PostQueue
    {
        $row = $this->scoped($context, 'queues')->where('id', '=', $id)->first();

        return $row === null ? null : $this->hydrate($row);
    }

    public function count(WorkspaceContext $context): int
    {
        return $this->scoped($context, 'queues')->count();
    }

    /**
     * @param list<array{weekday: int, time: string}> $slots
     * @param list<int> $channelIds
     */
    public function create(WorkspaceContext $context, string $name, array $slots, array $channelIds): PostQueue
    {
        $now = DbTime::format($this->clock->now());
        $publicId = (string) new Ulid();
        $this->db->transaction(function () use ($context, $name, $slots, $channelIds, $now, $publicId): void {
            $id = (int) $this->db->table('queues')->insert([
                'public_id' => $publicId, 'workspace_id' => $context->workspaceId, 'name' => mb_substr($name, 0, 100), 'paused' => 0,
                'created_by' => $context->userId, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->writeSlotsAndChannels($id, $slots, $channelIds);
        });

        return $this->find($context, $publicId) ?? throw new \LogicException('The inserted queue disappeared.');
    }

    /**
     * @param list<array{weekday: int, time: string}> $slots
     * @param list<int> $channelIds
     */
    public function update(WorkspaceContext $context, PostQueue $queue, string $name, array $slots, array $channelIds): void
    {
        $this->db->transaction(function () use ($context, $queue, $name, $slots, $channelIds): void {
            $this->scoped($context, 'queues')->where('id', '=', $queue->id)->update(['name' => mb_substr($name, 0, 100), 'updated_at' => DbTime::format($this->clock->now())]);
            $this->db->table('queue_slots')->where('queue_id', '=', $queue->id)->delete();
            $this->db->table('queue_channels')->where('queue_id', '=', $queue->id)->delete();
            $this->writeSlotsAndChannels($queue->id, $slots, $channelIds);
        });
    }

    public function setPaused(WorkspaceContext $context, PostQueue $queue, bool $paused): void
    {
        $this->scoped($context, 'queues')->where('id', '=', $queue->id)->update(['paused' => $paused ? 1 : 0, 'updated_at' => DbTime::format($this->clock->now())]);
    }

    public function delete(WorkspaceContext $context, PostQueue $queue): void
    {
        $this->scoped($context, 'queues')->where('id', '=', $queue->id)->delete();
    }

    // ---- entries ---------------------------------------------------------------------------------------------------

    /**
     * @return list<QueueEntry> in queue order
     */
    public function entries(WorkspaceContext $context, PostQueue $queue): array
    {
        $this->assertOwn($context, $queue);

        return array_map(self::entry(...), $this->db->table('queue_entries')->where('queue_id', '=', $queue->id)->orderBy('position')->orderBy('id')->get());
    }

    public function entryOfPost(WorkspaceContext $context, int $postId): ?QueueEntry
    {
        $row = $this->db->select(
            'SELECT e.* FROM queue_entries e JOIN queues q ON q.id = e.queue_id WHERE e.post_id = ? AND q.workspace_id = ? LIMIT 1',
            [$postId, $context->workspaceId],
        );

        return $row === [] ? null : self::entry($row[0]);
    }

    public function addEntry(WorkspaceContext $context, PostQueue $queue, int $postId, string $source): void
    {
        $this->assertOwn($context, $queue);
        $last = $this->db->select('SELECT COALESCE(MAX(position), 0) AS p FROM queue_entries WHERE queue_id = ?', [$queue->id]);
        $this->db->table('queue_entries')->insert([
            'queue_id' => $queue->id, 'post_id' => $postId, 'position' => (int) ($last[0]['p'] ?? 0) + 1, 'source' => $source,
            'created_at' => DbTime::format($this->clock->now()),
        ]);
    }

    public function removeEntry(WorkspaceContext $context, PostQueue $queue, int $postId): void
    {
        $this->assertOwn($context, $queue);
        $this->db->table('queue_entries')->where('queue_id', '=', $queue->id)->where('post_id', '=', $postId)->delete();
    }

    public function setNote(WorkspaceContext $context, QueueEntry $entry, ?string $note): void
    {
        $queue = $this->findById($context, $entry->queueId);
        if ($queue !== null) {
            $this->db->table('queue_entries')->where('id', '=', $entry->id)->update(['note' => $note === null ? null : mb_substr($note, 0, 255)]);
        }
    }

    /**
     * Put the entries in the given order (post ids), renumbering positions from 1.
     *
     * @param list<int> $postIds
     */
    public function reorder(WorkspaceContext $context, PostQueue $queue, array $postIds): void
    {
        $this->assertOwn($context, $queue);
        $this->db->transaction(function () use ($queue, $postIds): void {
            foreach ($postIds as $i => $postId) {
                $this->db->table('queue_entries')->where('queue_id', '=', $queue->id)->where('post_id', '=', $postId)->update(['position' => $i + 1]);
            }
        });
    }

    private function assertOwn(WorkspaceContext $context, PostQueue $queue): void
    {
        if ($queue->workspaceId !== $context->workspaceId) {
            throw new \LogicException('A queue of another workspace.');
        }
    }

    /**
     * @param list<array{weekday: int, time: string}> $slots
     * @param list<int> $channelIds
     */
    private function writeSlotsAndChannels(int $queueId, array $slots, array $channelIds): void
    {
        foreach ($slots as $slot) {
            $this->db->table('queue_slots')->insert(['queue_id' => $queueId, 'weekday' => $slot['weekday'], 'at_time' => $slot['time']]);
        }
        foreach (array_unique($channelIds) as $channelId) {
            $this->db->table('queue_channels')->insert(['queue_id' => $queueId, 'channel_id' => $channelId]);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): PostQueue
    {
        $id = (int) $row['id'];
        $slots = array_map(static fn (array $s): array => ['weekday' => (int) $s['weekday'], 'time' => (string) $s['at_time']], $this->db->table('queue_slots')->where('queue_id', '=', $id)->orderBy('weekday')->orderBy('at_time')->get());
        $channels = array_map(static fn (array $c): int => (int) $c['channel_id'], $this->db->table('queue_channels')->where('queue_id', '=', $id)->get());

        return new PostQueue($id, (string) $row['public_id'], (int) $row['workspace_id'], (string) $row['name'], (int) $row['paused'] === 1, $slots, $channels);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function entry(array $row): QueueEntry
    {
        return new QueueEntry((int) $row['id'], (int) $row['queue_id'], (int) $row['post_id'], (int) $row['position'], (string) $row['source'], isset($row['note']) ? (string) $row['note'] : null);
    }
}
