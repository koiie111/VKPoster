<?php

declare(strict_types=1);

namespace App\Domain\Automation\Crosspost;

use App\Domain\Workspace\WorkspaceContext;
use App\Domain\Workspace\WorkspaceScopedRepository;
use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;
use Symfony\Component\Uid\Ulid;

/**
 * Cross-posting rules of a workspace and their target channels.
 */
final class CrosspostRepository extends WorkspaceScopedRepository
{
    public function __construct(Connection $db, private readonly Clock $clock)
    {
        parent::__construct($db);
    }

    /**
     * @return list<CrosspostRule> oldest first
     */
    public function all(WorkspaceContext $context): array
    {
        return array_map($this->hydrate(...), $this->scoped($context, 'crosspost_rules')->orderBy('id')->limit(200)->get());
    }

    public function find(WorkspaceContext $context, string $publicId): ?CrosspostRule
    {
        $row = $this->scoped($context, 'crosspost_rules')->where('public_id', '=', strtoupper($publicId))->first();

        return $row === null ? null : $this->hydrate($row);
    }

    public function findById(WorkspaceContext $context, int $id): ?CrosspostRule
    {
        $row = $this->scoped($context, 'crosspost_rules')->where('id', '=', $id)->first();

        return $row === null ? null : $this->hydrate($row);
    }

    public function count(WorkspaceContext $context): int
    {
        return $this->scoped($context, 'crosspost_rules')->count();
    }

    /**
     * @param list<int> $targetIds
     */
    public function create(WorkspaceContext $context, int $sourceChannelId, string $name, CrosspostFilter $filter, CrosspostTransform $transform, int $delayMinutes, string $mode, bool $syncEdits, array $targetIds): CrosspostRule
    {
        $now = DbTime::format($this->clock->now());
        $publicId = (string) new Ulid();
        $this->db->transaction(function () use ($context, $sourceChannelId, $name, $filter, $transform, $delayMinutes, $mode, $syncEdits, $targetIds, $now, $publicId): void {
            $id = (int) $this->db->table('crosspost_rules')->insert([
                'public_id' => $publicId, 'workspace_id' => $context->workspaceId, 'source_channel_id' => $sourceChannelId, 'name' => mb_substr($name, 0, 100),
                'enabled' => 1, 'filters_json' => json_encode($filter->toArray(), JSON_THROW_ON_ERROR), 'transforms_json' => json_encode($transform->toArray(), JSON_THROW_ON_ERROR),
                'delay_minutes' => $delayMinutes, 'mode' => $mode, 'sync_edits' => $syncEdits ? 1 : 0, 'created_by' => $context->userId, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->writeTargets($id, $targetIds);
        });

        return $this->find($context, $publicId) ?? throw new \LogicException('The inserted rule disappeared.');
    }

    /**
     * @param list<int> $targetIds
     */
    public function update(WorkspaceContext $context, CrosspostRule $rule, string $name, CrosspostFilter $filter, CrosspostTransform $transform, int $delayMinutes, string $mode, bool $syncEdits, array $targetIds): void
    {
        $this->db->transaction(function () use ($context, $rule, $name, $filter, $transform, $delayMinutes, $mode, $syncEdits, $targetIds): void {
            $this->scoped($context, 'crosspost_rules')->where('id', '=', $rule->id)->update([
                'name' => mb_substr($name, 0, 100), 'filters_json' => json_encode($filter->toArray(), JSON_THROW_ON_ERROR), 'transforms_json' => json_encode($transform->toArray(), JSON_THROW_ON_ERROR),
                'delay_minutes' => $delayMinutes, 'mode' => $mode, 'sync_edits' => $syncEdits ? 1 : 0, 'updated_at' => DbTime::format($this->clock->now()),
            ]);
            $this->db->table('crosspost_targets')->where('rule_id', '=', $rule->id)->delete();
            $this->writeTargets($rule->id, $targetIds);
        });
    }

    public function setEnabled(WorkspaceContext $context, CrosspostRule $rule, bool $enabled): void
    {
        $this->scoped($context, 'crosspost_rules')->where('id', '=', $rule->id)->update(['enabled' => $enabled ? 1 : 0, 'updated_at' => DbTime::format($this->clock->now())]);
    }

    public function delete(WorkspaceContext $context, CrosspostRule $rule): void
    {
        $this->scoped($context, 'crosspost_rules')->where('id', '=', $rule->id)->delete();
    }

    /**
     * @param list<int> $targetIds
     */
    private function writeTargets(int $ruleId, array $targetIds): void
    {
        foreach (array_unique($targetIds) as $channelId) {
            $this->db->table('crosspost_targets')->insert(['rule_id' => $ruleId, 'channel_id' => $channelId]);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    public function hydrate(array $row): CrosspostRule
    {
        $filters = json_decode((string) $row['filters_json'], true);
        $transforms = json_decode((string) $row['transforms_json'], true);
        $targets = array_map(static fn (array $t): int => (int) $t['channel_id'], $this->db->table('crosspost_targets')->where('rule_id', '=', (int) $row['id'])->get());

        return new CrosspostRule(
            (int) $row['id'],
            (string) $row['public_id'],
            (int) $row['workspace_id'],
            (int) $row['source_channel_id'],
            (string) $row['name'],
            (int) $row['enabled'] === 1,
            CrosspostFilter::fromArray(is_array($filters) ? $filters : []),
            CrosspostTransform::fromArray(is_array($transforms) ? $transforms : []),
            (int) $row['delay_minutes'],
            (string) $row['mode'],
            (int) $row['sync_edits'] === 1,
            $targets,
            DbTime::parse($row['last_run_at'] ?? null),
            isset($row['last_note']) ? (string) $row['last_note'] : null,
            (int) ($row['posts_created'] ?? 0),
            isset($row['created_by']) ? (int) $row['created_by'] : null,
        );
    }
}
