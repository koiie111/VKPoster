<?php

declare(strict_types=1);

namespace App\Domain\Automation\Crosspost;

use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;

/**
 * Cross-posting across workspaces, for the bot's update handler and the worker where no member is signed in: the buffer of
 * incoming channel posts (which also makes a redelivered update harmless), the claim that lets one run handle a group of
 * messages, and the table that remembers which source message became which post.
 */
final class CrosspostSystem
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    public static function groupKey(SourceMessage $message): string
    {
        return $message->groupId !== null ? 'g' . $message->groupId : 'm' . $message->messageId;
    }

    /**
     * Keep an incoming message. Returns false when this very message was received before (Telegram resends updates it thinks were lost).
     */
    public function buffer(SourceMessage $message): bool
    {
        return $this->db->execute(
            'INSERT IGNORE INTO crosspost_buffer (chat_id, message_id, group_key, payload_json, created_at) VALUES (?, ?, ?, ?, ?)',
            [$message->chatId, $message->messageId, self::groupKey($message), json_encode($message->toArray(), JSON_THROW_ON_ERROR), DbTime::format($this->clock->now())],
        ) === 1;
    }

    /**
     * Take the buffered messages of a group for processing: only the first caller gets them, later ones get an empty list.
     *
     * @return list<SourceMessage> in message order
     */
    public function claim(int $chatId, string $groupKey): array
    {
        $stamp = DbTime::format($this->clock->now());
        $taken = $this->db->execute('UPDATE crosspost_buffer SET taken_at = ? WHERE chat_id = ? AND group_key = ? AND taken_at IS NULL', [$stamp, $chatId, $groupKey]);
        if ($taken === 0) {
            return [];
        }
        $messages = [];
        foreach ($this->db->table('crosspost_buffer')->where('chat_id', '=', $chatId)->where('group_key', '=', $groupKey)->where('taken_at', '=', $stamp)->orderBy('message_id')->get() as $row) {
            $payload = json_decode((string) $row['payload_json'], true);
            $message = is_array($payload) ? SourceMessage::fromArray($payload) : null;
            if ($message !== null) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    /**
     * Enabled rules of a source channel. Rows only, the caller builds the context.
     *
     * @return list<array{id: int, workspace_id: int, created_by: ?int}>
     */
    public function rulesOfChannel(int $channelId): array
    {
        $rows = $this->db->table('crosspost_rules')->where('source_channel_id', '=', $channelId)->where('enabled', '=', 1)->get();

        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'workspace_id' => (int) $r['workspace_id'], 'created_by' => isset($r['created_by']) ? (int) $r['created_by'] : null], $rows);
    }

    /**
     * Remember that a source message was handled by a rule. False = it was handled already (the rule never posts the same message twice).
     */
    public function claimMessage(int $ruleId, int $messageId): bool
    {
        return $this->db->execute('INSERT IGNORE INTO crosspost_items (rule_id, message_id, created_at) VALUES (?, ?, ?)', [$ruleId, $messageId, DbTime::format($this->clock->now())]) === 1;
    }

    /**
     * @param list<int> $messageIds
     */
    public function attachPost(int $ruleId, array $messageIds, int $postId): void
    {
        if ($messageIds !== []) {
            $this->db->table('crosspost_items')->where('rule_id', '=', $ruleId)->whereIn('message_id', $messageIds)->update(['post_id' => $postId]);
        }
    }

    /**
     * Posts made from one source message, by rule (for edits in the source).
     *
     * @return list<array{rule_id: int, post_id: int}>
     */
    public function postsOfMessage(int $chatId, int $messageId): array
    {
        $rows = $this->db->select(
            'SELECT i.rule_id, i.post_id FROM crosspost_items i JOIN crosspost_rules r ON r.id = i.rule_id JOIN channels c ON c.id = r.source_channel_id
             WHERE i.message_id = ? AND c.external_id = ? AND c.platform = ? AND i.post_id IS NOT NULL',
            [$messageId, (string) $chatId, 'telegram'],
        );

        return array_map(static fn (array $r): array => ['rule_id' => (int) $r['rule_id'], 'post_id' => (int) $r['post_id']], $rows);
    }

    /**
     * @return array{workspace_id: int, created_by: ?int, sync_edits: bool}|null
     */
    public function ruleHeader(int $ruleId): ?array
    {
        $row = $this->db->table('crosspost_rules')->where('id', '=', $ruleId)->first();

        return $row === null ? null : ['workspace_id' => (int) $row['workspace_id'], 'created_by' => isset($row['created_by']) ? (int) $row['created_by'] : null, 'sync_edits' => (int) $row['sync_edits'] === 1];
    }

    public function noteRun(int $ruleId, string $note, int $created): void
    {
        $this->db->execute('UPDATE crosspost_rules SET last_run_at = ?, last_note = ?, posts_created = posts_created + ? WHERE id = ?', [DbTime::format($this->clock->now()), mb_substr($note, 0, 255), $created, $ruleId]);
    }

    /**
     * Housekeeping: the buffer only has to outlive edits that arrive soon after a post.
     *
     * @return int rows deleted
     */
    public function prune(): int
    {
        return $this->db->table('crosspost_buffer')->where('created_at', '<', DbTime::format($this->clock->now()->modify('-2 days')))->delete();
    }
}
