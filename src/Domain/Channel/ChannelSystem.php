<?php

declare(strict_types=1);

namespace App\Domain\Channel;

use App\Integrations\Social\Contracts\Platform;
use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;
use DateTimeImmutable;

/**
 * Channel access that spans workspaces, for background work and for the bot's webhook, where no member is signed in:
 * finding the channels behind a Telegram chat, picking channels for the next health check, recording a verdict.
 * Pages and workspace actions never use it; they go through `ChannelRepository`.
 */
final class ChannelSystem
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    public function find(int $id): ?Channel
    {
        $row = $this->db->table('channels')->where('id', '=', $id)->first();

        return $row === null ? null : ChannelRepository::hydrate($row);
    }

    /**
     * Every channel (of any workspace) that lives in this chat of the platform and publishes through the shared bot.
     *
     * @return list<Channel>
     */
    public function sharedBotChannels(Platform $platform, string $externalId): array
    {
        $rows = $this->db->table('channels')->where('platform', '=', $platform->value)->where('external_id', '=', $externalId)
            ->where('mode', '=', ChannelMode::SharedBot->value)->get();

        return array_map(ChannelRepository::hydrate(...), $rows);
    }

    /**
     * Channels whose last check is older than `$olderThan` (or never happened). Paused and revoked ones are skipped:
     * the owner decided about the first, and the second cannot recover without the owner.
     *
     * @param list<Platform> $platforms
     * @return list<int> channel ids, least recently checked first
     */
    public function dueForHealthCheck(array $platforms, DateTimeImmutable $olderThan, int $limit): array
    {
        if ($platforms === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($platforms), '?'));
        $rows = $this->db->select(
            'SELECT id FROM channels WHERE status IN (\'active\', \'error\') AND platform IN (' . $marks . ') AND (last_health_at IS NULL OR last_health_at < ?) ORDER BY last_health_at IS NULL DESC, last_health_at ASC LIMIT ' . max(1, $limit),
            [...array_map(static fn (Platform $p): string => $p->value, $platforms), DbTime::format($olderThan)],
        );

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /**
     * Channels that use up the plan's channel allowance: everything that is not paused.
     */
    public function countOccupying(int $workspaceId): int
    {
        return $this->db->table('channels')->where('workspace_id', '=', $workspaceId)->where('status', '!=', ChannelStatus::Paused->value)->count();
    }

    public function countAll(int $workspaceId): int
    {
        return $this->db->table('channels')->where('workspace_id', '=', $workspaceId)->count();
    }

    /**
     * Channels that use up the plan's allowance, oldest first (the newest are the first to be paused when the allowance shrinks).
     *
     * @return list<Channel>
     */
    public function occupying(int $workspaceId): array
    {
        $rows = $this->db->select(
            'SELECT * FROM channels WHERE workspace_id = ? AND status <> ? ORDER BY created_at ASC, id ASC',
            [$workspaceId, ChannelStatus::Paused->value],
        );

        return array_map(ChannelRepository::hydrate(...), $rows);
    }

    /**
     * Pause a channel on the system's initiative (a plan change). Nothing is deleted; the reason is shown next to the channel.
     */
    public function pause(Channel $channel, string $reason): void
    {
        $this->db->table('channels')->where('id', '=', $channel->id)->update([
            'status' => ChannelStatus::Paused->value,
            'last_error' => mb_substr($reason, 0, 500),
            'updated_at' => DbTime::format($this->clock->now()),
        ]);
    }

    /**
     * @param array<string, bool> $rights
     */
    public function recordHealthy(Channel $channel, array $rights, ?string $title): void
    {
        $settings = $channel->settings;
        if ($rights !== []) {
            $settings['rights'] = $rights;
        }
        $update = [
            'status' => $channel->status === ChannelStatus::Paused ? ChannelStatus::Paused->value : ChannelStatus::Active->value,
            'last_error' => null,
            'last_health_at' => DbTime::format($this->clock->now()),
            'settings_json' => json_encode($settings, JSON_THROW_ON_ERROR),
            'updated_at' => DbTime::format($this->clock->now()),
        ];
        if ($title !== null && $title !== '') {
            $update['title'] = mb_substr($title, 0, 255);
        }
        $this->db->table('channels')->where('id', '=', $channel->id)->update($update);
    }

    public function recordBroken(Channel $channel, ChannelStatus $status, string $message): void
    {
        $this->db->table('channels')->where('id', '=', $channel->id)->update([
            'status' => $status->value,
            'last_error' => mb_substr($message, 0, 500),
            'last_health_at' => DbTime::format($this->clock->now()),
            'updated_at' => DbTime::format($this->clock->now()),
        ]);
    }

    /**
     * The check could not be completed (network, rate limit): remember when we tried, keep the status.
     */
    public function recordAttempt(Channel $channel): void
    {
        $this->db->table('channels')->where('id', '=', $channel->id)->update(['last_health_at' => DbTime::format($this->clock->now())]);
    }

    /**
     * People to tell when a channel breaks: the owner and the administrators of its workspace.
     *
     * @return list<array{email: string, name: string}>
     */
    public function alertRecipients(int $workspaceId): array
    {
        $rows = $this->db->select(
            'SELECT u.email, u.name FROM workspace_members m JOIN users u ON u.id = m.user_id WHERE m.workspace_id = ? AND m.role IN (\'owner\', \'admin\') AND u.email IS NOT NULL AND u.email_verified_at IS NOT NULL AND u.status = \'active\' ORDER BY m.joined_at',
            [$workspaceId],
        );

        return array_map(static fn (array $row): array => ['email' => (string) $row['email'], 'name' => (string) $row['name']], $rows);
    }

    /**
     * @return array{name: string, public_id: string}|null
     */
    public function workspaceInfo(int $workspaceId): ?array
    {
        $row = $this->db->table('workspaces')->where('id', '=', $workspaceId)->first();

        return $row === null ? null : ['name' => (string) $row['name'], 'public_id' => (string) $row['public_id']];
    }

    public function setAvatar(Channel $channel, ?string $key): void
    {
        $this->db->table('channels')->where('id', '=', $channel->id)->update(['avatar_key' => $key]);
    }
}
