<?php

declare(strict_types=1);

namespace App\Domain\Notification;

use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;

/**
 * Links a person's account to their private chat with the shared bot, so notifications can reach them in Telegram. The person
 * asks for a one-time token on the settings page and opens `t.me/<bot>?start=<token>`; the bot hands the token back here with the
 * chat id. Only the SHA-256 of the token is stored; it lives 15 minutes and works once.
 */
final class TelegramLinks
{
    private const TTL_SECONDS = 900;

    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    /**
     * A new token (the plain value is returned once and never stored). Earlier unused tokens of the person stop working.
     */
    public function issueToken(int $userId): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $now = $this->clock->now();
        $this->db->transaction(function (Connection $db) use ($userId, $token, $now): void {
            $db->table('telegram_link_tokens')->where('user_id', '=', $userId)->delete();
            $db->table('telegram_link_tokens')->insert([
                'user_id' => $userId,
                'token_hash' => hash('sha256', $token),
                'expires_at' => DbTime::format($now->modify(sprintf('+%d seconds', self::TTL_SECONDS))),
                'created_at' => DbTime::format($now),
            ]);
        });

        return $token;
    }

    /**
     * Consume a token and link the chat. Returns the user id, or null for an unknown, expired or used token.
     */
    public function redeem(string $token, int $chatId, ?string $username): ?int
    {
        if (preg_match('/^[A-Za-z0-9_-]{20,64}$/', $token) !== 1) {
            return null;
        }
        $now = $this->clock->now();

        return $this->db->transaction(function (Connection $db) use ($token, $chatId, $username, $now): ?int {
            $row = $db->table('telegram_link_tokens')->where('token_hash', '=', hash('sha256', $token))->forUpdate()->first();
            if ($row === null || $row['used_at'] !== null || (DbTime::parse($row['expires_at']) ?? $now) < $now) {
                return null;
            }
            $userId = (int) $row['user_id'];
            $db->table('telegram_link_tokens')->where('id', '=', (int) $row['id'])->update(['used_at' => DbTime::format($now)]);
            // One chat belongs to one account, and one account has one chat: a new link replaces the old ones.
            $db->table('telegram_links')->where('chat_id', '=', $chatId)->delete();
            $db->execute(
                'INSERT INTO telegram_links (user_id, chat_id, username, linked_at) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE chat_id = VALUES(chat_id), username = VALUES(username), linked_at = VALUES(linked_at)',
                [$userId, $chatId, $username, DbTime::format($now)],
            );

            return $userId;
        });
    }

    /**
     * @return array{chat_id: int, username: ?string}|null
     */
    public function linkOf(int $userId): ?array
    {
        $row = $this->db->table('telegram_links')->where('user_id', '=', $userId)->first();

        return $row === null ? null : ['chat_id' => (int) $row['chat_id'], 'username' => is_string($row['username'] ?? null) ? $row['username'] : null];
    }

    public function unlink(int $userId): void
    {
        $this->db->table('telegram_links')->where('user_id', '=', $userId)->delete();
    }

    /**
     * Housekeeping: tokens past their time.
     */
    public function prune(): int
    {
        return $this->db->table('telegram_link_tokens')->where('expires_at', '<', DbTime::format($this->clock->now()))->delete();
    }
}
