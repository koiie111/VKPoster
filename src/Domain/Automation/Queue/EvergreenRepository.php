<?php

declare(strict_types=1);

namespace App\Domain\Automation\Queue;

use App\Domain\Workspace\WorkspaceContext;
use App\Domain\Workspace\WorkspaceScopedRepository;
use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;
use DateTimeImmutable;
use Symfony\Component\Uid\Ulid;

/**
 * Evergreen pools of a workspace and the posts in them.
 */
final class EvergreenRepository extends WorkspaceScopedRepository
{
    public function __construct(Connection $db, private readonly Clock $clock)
    {
        parent::__construct($db);
    }

    /**
     * @return list<EvergreenPool>
     */
    public function all(WorkspaceContext $context): array
    {
        return array_map(self::hydrate(...), $this->scoped($context, 'evergreen_pools')->orderBy('id')->limit(100)->get());
    }

    public function find(WorkspaceContext $context, string $publicId): ?EvergreenPool
    {
        $row = $this->scoped($context, 'evergreen_pools')->where('public_id', '=', strtoupper($publicId))->first();

        return $row === null ? null : self::hydrate($row);
    }

    public function findById(WorkspaceContext $context, int $id): ?EvergreenPool
    {
        $row = $this->scoped($context, 'evergreen_pools')->where('id', '=', $id)->first();

        return $row === null ? null : self::hydrate($row);
    }

    public function count(WorkspaceContext $context): int
    {
        return $this->scoped($context, 'evergreen_pools')->count();
    }

    public function create(WorkspaceContext $context, int $queueId, string $name, int $minIntervalDays, int $keepAhead): EvergreenPool
    {
        $now = DbTime::format($this->clock->now());
        $publicId = (string) new Ulid();
        $this->db->table('evergreen_pools')->insert([
            'public_id' => $publicId, 'workspace_id' => $context->workspaceId, 'queue_id' => $queueId, 'name' => mb_substr($name, 0, 100),
            'min_interval_days' => $minIntervalDays, 'keep_ahead' => $keepAhead, 'enabled' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $this->find($context, $publicId) ?? throw new \LogicException('The inserted pool disappeared.');
    }

    public function setEnabled(WorkspaceContext $context, EvergreenPool $pool, bool $enabled): void
    {
        $this->scoped($context, 'evergreen_pools')->where('id', '=', $pool->id)->update(['enabled' => $enabled ? 1 : 0, 'updated_at' => DbTime::format($this->clock->now())]);
    }

    public function delete(WorkspaceContext $context, EvergreenPool $pool): void
    {
        $this->scoped($context, 'evergreen_pools')->where('id', '=', $pool->id)->delete();
    }

    /**
     * @return list<array{id: int, post_id: int, last_used_at: ?DateTimeImmutable, uses: int}> the least recently used first
     */
    public function posts(WorkspaceContext $context, EvergreenPool $pool): array
    {
        $this->assertOwn($context, $pool);
        $rows = $this->db->select('SELECT id, post_id, last_used_at, uses FROM evergreen_posts WHERE pool_id = ? ORDER BY last_used_at IS NULL DESC, last_used_at ASC, uses ASC, id ASC', [$pool->id]);

        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'post_id' => (int) $r['post_id'], 'last_used_at' => DbTime::parse($r['last_used_at']), 'uses' => (int) $r['uses']], $rows);
    }

    public function addPost(WorkspaceContext $context, EvergreenPool $pool, int $postId): bool
    {
        $this->assertOwn($context, $pool);

        return $this->db->execute('INSERT IGNORE INTO evergreen_posts (pool_id, post_id, created_at) VALUES (?, ?, ?)', [$pool->id, $postId, DbTime::format($this->clock->now())]) === 1;
    }

    public function removePost(WorkspaceContext $context, EvergreenPool $pool, int $postId): void
    {
        $this->assertOwn($context, $pool);
        $this->db->table('evergreen_posts')->where('pool_id', '=', $pool->id)->where('post_id', '=', $postId)->delete();
    }

    public function markUsed(WorkspaceContext $context, EvergreenPool $pool, int $postId, DateTimeImmutable $at): void
    {
        $this->assertOwn($context, $pool);
        $this->db->execute('UPDATE evergreen_posts SET last_used_at = ?, uses = uses + 1 WHERE pool_id = ? AND post_id = ?', [DbTime::format($at), $pool->id, $postId]);
    }

    /**
     * Copies of this pool that still wait in the queue.
     */
    public function waitingCopies(WorkspaceContext $context, EvergreenPool $pool): int
    {
        $this->assertOwn($context, $pool);
        $row = $this->db->select('SELECT COUNT(*) AS c FROM queue_entries WHERE queue_id = ? AND source = ?', [$pool->queueId, 'evergreen:' . $pool->publicId]);

        return (int) ($row[0]['c'] ?? 0);
    }

    private function assertOwn(WorkspaceContext $context, EvergreenPool $pool): void
    {
        if ($pool->workspaceId !== $context->workspaceId) {
            throw new \LogicException('A pool of another workspace.');
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): EvergreenPool
    {
        return new EvergreenPool((int) $row['id'], (string) $row['public_id'], (int) $row['workspace_id'], (int) $row['queue_id'], (string) $row['name'], (int) $row['min_interval_days'], (int) $row['keep_ahead'], (int) $row['enabled'] === 1);
    }
}
