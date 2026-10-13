<?php

declare(strict_types=1);

namespace App\Domain\Automation\Trigger;

use App\Domain\Workspace\WorkspaceContext;
use App\Domain\Workspace\WorkspaceScopedRepository;
use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;
use DateTimeImmutable;
use Symfony\Component\Uid\Ulid;

/**
 * Triggers of a workspace.
 */
final class TriggerRepository extends WorkspaceScopedRepository
{
    public function __construct(Connection $db, private readonly Clock $clock)
    {
        parent::__construct($db);
    }

    /**
     * @return list<Trigger> newest first
     */
    public function forPost(WorkspaceContext $context, int $postId): array
    {
        return array_map(self::hydrate(...), $this->scoped($context, 'triggers')->where('post_id', '=', $postId)->orderBy('id', 'desc')->limit(50)->get());
    }

    /**
     * @return list<Trigger> newest first
     */
    public function forChannel(WorkspaceContext $context, int $channelId): array
    {
        return array_map(self::hydrate(...), $this->scoped($context, 'triggers')->where('channel_id', '=', $channelId)->orderBy('id', 'desc')->limit(50)->get());
    }

    public function find(WorkspaceContext $context, string $publicId): ?Trigger
    {
        $row = $this->scoped($context, 'triggers')->where('public_id', '=', strtoupper($publicId))->first();

        return $row === null ? null : self::hydrate($row);
    }

    public function count(WorkspaceContext $context): int
    {
        return $this->scoped($context, 'triggers')->count();
    }

    public function countForPost(WorkspaceContext $context, int $postId): int
    {
        return $this->scoped($context, 'triggers')->where('post_id', '=', $postId)->count();
    }

    /**
     * @param array<string, mixed> $params
     */
    public function create(WorkspaceContext $context, ?int $postId, ?int $channelId, TriggerEvent $event, int $delayMinutes, ?DateTimeImmutable $fireAt, TriggerAction $action, array $params): Trigger
    {
        $publicId = (string) new Ulid();
        $this->db->table('triggers')->insert([
            'public_id' => $publicId, 'workspace_id' => $context->workspaceId, 'post_id' => $postId, 'channel_id' => $channelId,
            'event' => $event->value, 'delay_minutes' => $delayMinutes, 'fire_at' => $fireAt === null ? null : DbTime::format($fireAt),
            'action' => $action->value, 'params_json' => json_encode($params, JSON_THROW_ON_ERROR),
            'status' => $fireAt === null ? 'pending' : 'armed', 'created_by' => $context->userId, 'created_at' => DbTime::format($this->clock->now()),
        ]);

        return $this->find($context, $publicId) ?? throw new \LogicException('The inserted trigger disappeared.');
    }

    public function delete(WorkspaceContext $context, Trigger $trigger): void
    {
        $this->scoped($context, 'triggers')->where('id', '=', $trigger->id)->delete();
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function hydrate(array $row): Trigger
    {
        $params = json_decode((string) ($row['params_json'] ?? '{}'), true);

        return new Trigger(
            (int) $row['id'],
            (string) $row['public_id'],
            (int) $row['workspace_id'],
            isset($row['post_id']) ? (int) $row['post_id'] : null,
            isset($row['channel_id']) ? (int) $row['channel_id'] : null,
            isset($row['event_post_id']) ? (int) $row['event_post_id'] : null,
            TriggerEvent::from((string) $row['event']),
            (int) $row['delay_minutes'],
            DbTime::parse($row['fire_at'] ?? null),
            TriggerAction::from((string) $row['action']),
            is_array($params) ? $params : [],
            (string) $row['status'],
            DbTime::parse($row['fired_at'] ?? null),
            isset($row['result']) ? (string) $row['result'] : null,
            isset($row['created_by']) ? (int) $row['created_by'] : null,
        );
    }
}
