<?php

declare(strict_types=1);

namespace App\Domain\Workspace;

use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;

/**
 * Which channels a member may work with ("publishing rights without being an admin of the community").
 * A member is unrestricted (sees all channels) unless `channels_restricted` is set, which is always the
 * case for clients; then only the assigned channels count, and none assigned means none visible.
 */
final class ChannelAccessRepository extends WorkspaceScopedRepository
{
    public function __construct(Connection $db, private readonly Clock $clock)
    {
        parent::__construct($db);
    }

    /**
     * Channel ids the member may use, or null when every channel of the workspace is allowed.
     *
     * @return list<int>|null
     */
    public function allowed(WorkspaceContext $context, int $userId): ?array
    {
        $member = $this->scoped($context, 'workspace_members')->where('user_id', '=', $userId)->first();
        if ($member === null || ((int) $member['channels_restricted'] !== 1 && (string) $member['role'] !== Role::Client->value)) {
            return null;
        }
        $rows = $this->scoped($context, 'member_channel_access')->where('user_id', '=', $userId)->orderBy('channel_id')->get();

        return array_map(static fn (array $row): int => (int) $row['channel_id'], $rows);
    }

    /**
     * Replace a member's access. Null lifts the restriction; a list (possibly empty) restricts the member to it.
     * Clients cannot be unrestricted. Returns false when the person is not a member of the workspace.
     *
     * @param list<int>|null $channelIds
     */
    public function set(WorkspaceContext $context, int $userId, ?array $channelIds): bool
    {
        return $this->db->transaction(function () use ($context, $userId, $channelIds): bool {
            $member = $this->scoped($context, 'workspace_members')->where('user_id', '=', $userId)->forUpdate()->first();
            if ($member === null) {
                return false;
            }
            $restricted = $channelIds !== null || (string) $member['role'] === Role::Client->value;
            $this->scoped($context, 'workspace_members')->where('user_id', '=', $userId)->update(['channels_restricted' => $restricted ? 1 : 0]);
            $this->scoped($context, 'member_channel_access')->where('user_id', '=', $userId)->delete();
            $now = DbTime::format($this->clock->now());
            foreach (array_values(array_unique($channelIds ?? [])) as $channelId) {
                $this->db->table('member_channel_access')->insert([
                    'workspace_id' => $context->workspaceId,
                    'user_id' => $userId,
                    'channel_id' => $channelId,
                    'granted_at' => $now,
                ]);
            }

            return true;
        });
    }
}
