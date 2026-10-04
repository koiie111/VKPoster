<?php

declare(strict_types=1);

namespace App\Domain\Workspace;

use App\Support\DbTime;
use DateTimeImmutable;

/**
 * Members of one workspace (team list, role changes, removal). Every method is limited to the
 * workspace of the given context.
 */
final class MemberRepository extends WorkspaceScopedRepository
{
    /**
     * @return list<Member> owner first, then by role power and join date
     */
    public function all(WorkspaceContext $context): array
    {
        $rows = $this->db->select(
            'SELECT m.public_id, m.user_id, m.role, m.channels_restricted, m.joined_at, u.name, u.email FROM workspace_members m JOIN users u ON u.id = m.user_id WHERE m.workspace_id = ? ORDER BY m.joined_at ASC, m.user_id ASC',
            [$context->workspaceId],
        );
        $members = array_map(self::hydrate(...), $rows);
        usort($members, static fn (Member $a, Member $b): int => $b->role->rank() <=> $a->role->rank());

        return $members;
    }

    public function find(WorkspaceContext $context, string $memberPublicId): ?Member
    {
        $rows = $this->db->select(
            'SELECT m.public_id, m.user_id, m.role, m.channels_restricted, m.joined_at, u.name, u.email FROM workspace_members m JOIN users u ON u.id = m.user_id WHERE m.workspace_id = ? AND m.public_id = ? LIMIT 1',
            [$context->workspaceId, strtoupper($memberPublicId)],
        );

        return $rows === [] ? null : self::hydrate($rows[0]);
    }

    public function findByEmail(WorkspaceContext $context, string $email): ?Member
    {
        $rows = $this->db->select(
            'SELECT m.public_id, m.user_id, m.role, m.channels_restricted, m.joined_at, u.name, u.email FROM workspace_members m JOIN users u ON u.id = m.user_id WHERE m.workspace_id = ? AND u.email = ? LIMIT 1',
            [$context->workspaceId, mb_strtolower(trim($email))],
        );

        return $rows === [] ? null : self::hydrate($rows[0]);
    }

    /**
     * Change a member's role. Clients are always limited to assigned channels; leaving the client role lifts
     * that limit (and forgets the assignments), while other roles keep whatever restriction they had.
     */
    public function setRole(WorkspaceContext $context, Member $member, Role $role): void
    {
        $update = ['role' => $role->value];
        if ($role === Role::Client) {
            $update['channels_restricted'] = 1;
        } elseif ($member->role === Role::Client) {
            $update['channels_restricted'] = 0;
            $this->scoped($context, 'member_channel_access')->where('user_id', '=', $member->userId)->delete();
        }
        $this->scoped($context, 'workspace_members')->where('user_id', '=', $member->userId)->update($update);
    }

    public function remove(WorkspaceContext $context, int $userId): bool
    {
        return $this->scoped($context, 'workspace_members')->where('user_id', '=', $userId)->delete() === 1;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): Member
    {
        return new Member(
            (string) $row['public_id'],
            (int) $row['user_id'],
            (string) $row['name'],
            is_string($row['email']) ? $row['email'] : null,
            Role::from((string) $row['role']),
            (int) $row['channels_restricted'] === 1,
            DbTime::parse($row['joined_at']) ?? new DateTimeImmutable('@0'),
        );
    }
}
