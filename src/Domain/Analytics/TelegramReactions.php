<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Domain\Channel\ChannelSystem;
use App\Integrations\Social\Contracts\Platform;
use App\Kernel\Database\Connection;

/** Admin shared-bot aggregate updates only; no user identities or invented reaction history. */
final class TelegramReactions
{
    public function __construct(private readonly Connection $db, private readonly ChannelSystem $channels)
    {
    }
    /** @param array<string,mixed> $event */
    public function ingest(array $event): void
    {
        $chat = $event['chat']['id'] ?? null;
        $message = $event['message_id'] ?? null;
        $date = $event['date'] ?? null;
        if (!is_int($chat) || !is_int($message) || !is_int($date) || !is_array($event['reactions'] ?? null)) {
            return;
        }
        $sum = 0;
        foreach ($event['reactions'] as $r) {
            if (!is_array($r) || !is_int($r['total_count'] ?? null) || $r['total_count'] < 0) {
                return;
            }
            $sum += $r['total_count'];
        }
        foreach ($this->channels->sharedBotChannels(Platform::Telegram, (string)$chat) as $channel) {
            $this->db->execute(
                'INSERT INTO telegram_stats_reactions(workspace_id,channel_id,message_id,event_date,reactions) VALUES(?,?,?,?,?) ON
                DUPLICATE KEY UPDATE
                reactions=IF(VALUES(event_date)>=event_date,VALUES(reactions),reactions),event_date=GREATEST(event_date,VALUES(event_date))',
                [$channel->workspaceId,
                $channel->id,
                (string)$message,
                $date,
                $sum]
            );
        }
    }
    public function count(int $workspace, int $channel, string $message): ?int
    {
        $r = $this->db->select(
            'SELECT reactions FROM telegram_stats_reactions WHERE workspace_id=? AND channel_id=? AND message_id=?',
            [$workspace,
            $channel,
            $message]
        )[0] ?? null;
        return $r === null ? null : (int)$r['reactions'];
    }
}
