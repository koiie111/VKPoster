<?php

declare(strict_types=1);

namespace App\Domain\Workspace;

use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;
use DateTimeImmutable;
use Symfony\Component\Uid\Ulid;

/**
 * Persistence for `workspaces` and the membership rows needed to *resolve* a context. This is the one
 * place where lookups happen before a `WorkspaceContext` exists (by public id, by user). Everything that
 * changes an existing workspace takes a context, so it can only touch the workspace the caller belongs to.
 */
final class WorkspaceRepository
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    /**
     * Create a workspace together with its owner membership, in one transaction.
     */
    public function create(int $ownerId, string $name, string $timezone, bool $personal = false): Workspace
    {
        $now = DbTime::format($this->clock->now());
        $publicId = (string) new Ulid();
        $this->db->transaction(function (Connection $db) use ($ownerId, $name, $timezone, $personal, $now, $publicId): void {
            $id = $db->table('workspaces')->insert([
                'public_id' => $publicId,
                'owner_id' => $ownerId,
                'name' => $name,
                'timezone' => $timezone,
                'is_personal' => $personal ? 1 : 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $db->table('workspace_members')->insert([
                'workspace_id' => (int) $id,
                'user_id' => $ownerId,
                'public_id' => (string) new Ulid(),
                'role' => Role::Owner->value,
                'joined_at' => $now,
            ]);
        });

        return $this->findByPublicId($publicId) ?? throw new \RuntimeException('The workspace was not saved.');
    }

    public function findByPublicId(string $publicId): ?Workspace
    {
        if (!Ulid::isValid($publicId)) {
            return null;
        }
        $row = $this->db->table('workspaces')->where('public_id', '=', strtoupper($publicId))->first();

        return $row === null ? null : $this->hydrate($row);
    }

    public function findById(int $id): ?Workspace
    {
        $row = $this->db->table('workspaces')->where('id', '=', $id)->first();

        return $row === null ? null : $this->hydrate($row);
    }

    public function membership(int $workspaceId, int $userId): ?Membership
    {
        $row = $this->db->table('workspace_members')->where('workspace_id', '=', $workspaceId)->where('user_id', '=', $userId)->first();

        return $row === null ? null : self::hydrateMembership($row);
    }

    /**
     * Workspaces a user belongs to: personal ones first, then by name.
     *
     * @return list<array{workspace: Workspace, role: Role}>
     */
    public function forUser(int $userId): array
    {
        $rows = $this->db->select(
            'SELECT w.*, m.role AS member_role FROM workspace_members m JOIN workspaces w ON w.id = m.workspace_id WHERE m.user_id = ? ORDER BY w.is_personal DESC, w.name ASC, w.id ASC',
            [$userId],
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = ['workspace' => $this->hydrate($row), 'role' => Role::from((string) $row['member_role'])];
        }

        return $out;
    }

    public function countOwnedBy(int $userId): int
    {
        return $this->db->table('workspaces')->where('owner_id', '=', $userId)->count();
    }

    /**
     * Add a person to a workspace (used when an invitation is accepted). False if they are already a member.
     */
    public function addMember(int $workspaceId, int $userId, Role $role, ?int $invitedBy): bool
    {
        if ($this->membership($workspaceId, $userId) !== null) {
            return false;
        }
        try {
            $this->db->table('workspace_members')->insert([
                'workspace_id' => $workspaceId,
                'user_id' => $userId,
                'public_id' => (string) new Ulid(),
                'role' => $role->value,
                'channels_restricted' => $role === Role::Client ? 1 : 0,
                'invited_by' => $invitedBy,
                'joined_at' => DbTime::format($this->clock->now()),
            ]);
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) {
                return false;
            }
            throw $e;
        }

        return true;
    }

    public function update(WorkspaceContext $context, string $name, string $timezone, string $locale): void
    {
        $this->db->table('workspaces')->where('id', '=', $context->workspaceId)->update([
            'name' => $name,
            'timezone' => $timezone,
            'locale' => $locale,
            'updated_at' => DbTime::format($this->clock->now()),
        ]);
    }

    public function delete(WorkspaceContext $context): void
    {
        $this->db->table('workspaces')->where('id', '=', $context->workspaceId)->delete();
    }

    /**
     * Hand the workspace to another member: they become the owner, the previous owner an admin.
     * Runs in a transaction that locks the workspace row, so two transfers cannot interleave.
     *
     * @return bool false when the target is not a member or the actor is no longer the owner
     */
    public function transferOwnership(WorkspaceContext $context, int $newOwnerId): bool
    {
        return $this->db->transaction(function (Connection $db) use ($context, $newOwnerId): bool {
            $rows = $db->table('workspaces')->where('id', '=', $context->workspaceId)->forUpdate()->get();
            if ($rows === [] || (int) $rows[0]['owner_id'] !== $context->userId || $newOwnerId === $context->userId) {
                return false;
            }
            $target = $db->table('workspace_members')->where('workspace_id', '=', $context->workspaceId)->where('user_id', '=', $newOwnerId)->first();
            if ($target === null) {
                return false;
            }
            $db->table('workspace_members')->where('workspace_id', '=', $context->workspaceId)->where('user_id', '=', $context->userId)->update(['role' => Role::Admin->value]);
            $db->table('workspace_members')->where('workspace_id', '=', $context->workspaceId)->where('user_id', '=', $newOwnerId)->update(['role' => Role::Owner->value, 'channels_restricted' => 0]);
            $db->table('workspaces')->where('id', '=', $context->workspaceId)->update(['owner_id' => $newOwnerId, 'updated_at' => DbTime::format($this->clock->now())]);

            return true;
        });
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function hydrateMembership(array $row): Membership
    {
        return new Membership(
            (int) $row['workspace_id'],
            (int) $row['user_id'],
            (string) $row['public_id'],
            Role::from((string) $row['role']),
            isset($row['invited_by']) ? (int) $row['invited_by'] : null,
            DbTime::parse($row['joined_at']) ?? new DateTimeImmutable('@0'),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Workspace
    {
        return new Workspace(
            (int) $row['id'],
            (string) $row['public_id'],
            (int) $row['owner_id'],
            (string) $row['name'],
            (string) $row['timezone'],
            (string) $row['locale'],
            (int) $row['is_personal'] === 1,
            DbTime::parse($row['created_at']) ?? new DateTimeImmutable('@0'),
        );
    }
}
