<?php

declare(strict_types=1);

namespace App\Domain\Link;

use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;

/**
 * Link settings of a workspace: the UTM rule, whether links are shortened, and per-channel UTM overrides. A channel either follows
 * the workspace (no row), switches tagging off or has its own rule. The workspace id is always passed in by callers that hold a
 * `WorkspaceContext`, so one workspace cannot read or change another's settings.
 */
final class LinkSettings
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    public function workspaceRule(int $workspaceId): UtmRule
    {
        $row = $this->workspaceRow($workspaceId);

        return $row === null ? new UtmRule() : $this->decode($row['utm_json']);
    }

    public function shortens(int $workspaceId): bool
    {
        return (int) ($this->workspaceRow($workspaceId)['shorten'] ?? 0) === 1;
    }

    public function saveWorkspace(int $workspaceId, UtmRule $rule, bool $shorten): void
    {
        $this->db->execute(
            'INSERT INTO link_workspace_settings (workspace_id, utm_json, shorten, updated_at) VALUES (?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE utm_json = VALUES(utm_json), shorten = VALUES(shorten), updated_at = VALUES(updated_at)',
            [$workspaceId, json_encode($rule->toArray(), JSON_THROW_ON_ERROR), $shorten ? 1 : 0, DbTime::format($this->clock->now())],
        );
    }

    /**
     * The channel's own rule, or null when the channel follows the workspace.
     */
    public function channelRule(int $workspaceId, int $channelId): ?UtmRule
    {
        $row = $this->db->select('SELECT rule_json FROM link_channel_rules WHERE workspace_id = ? AND channel_id = ?', [$workspaceId, $channelId])[0] ?? null;

        return $row === null ? null : $this->decode($row['rule_json']);
    }

    /** Saves a channel rule; null makes the channel follow the workspace again. */
    public function saveChannelRule(int $workspaceId, int $channelId, ?UtmRule $rule): void
    {
        if ($rule === null) {
            $this->db->execute('DELETE FROM link_channel_rules WHERE workspace_id = ? AND channel_id = ?', [$workspaceId, $channelId]);

            return;
        }
        $this->db->execute(
            'INSERT INTO link_channel_rules (channel_id, workspace_id, rule_json, updated_at) VALUES (?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE rule_json = VALUES(rule_json), updated_at = VALUES(updated_at)',
            [$channelId, $workspaceId, json_encode($rule->toArray(), JSON_THROW_ON_ERROR), DbTime::format($this->clock->now())],
        );
    }

    /** The rule that applies to a channel: its own when it has one, otherwise the workspace's. */
    public function effectiveRule(int $workspaceId, ?int $channelId): UtmRule
    {
        $own = $channelId === null ? null : $this->channelRule($workspaceId, $channelId);

        return $own ?? $this->workspaceRule($workspaceId);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function workspaceRow(int $workspaceId): ?array
    {
        return $this->db->select('SELECT utm_json, shorten FROM link_workspace_settings WHERE workspace_id = ?', [$workspaceId])[0] ?? null;
    }

    private function decode(mixed $json): UtmRule
    {
        $data = is_string($json) ? json_decode($json, true) : null;

        return UtmRule::fromArray(is_array($data) ? $data : []);
    }
}
