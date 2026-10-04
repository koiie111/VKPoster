<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Kernel\Database\Connection;
use App\Kernel\Session\SessionStore;
use App\Support\Clock;
use App\Support\DbTime;
use DateTimeImmutable;
use Symfony\Component\Uid\Ulid;

/**
 * Server-side list of a user's signed-in browsers (`user_sessions`). A row mirrors one Redis session:
 * revoking the row also deletes the session data, so "sign out this device" takes effect immediately.
 */
final class SessionRegistry
{
    private const TOUCH_INTERVAL = 60;

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly SessionStore $store,
    ) {
    }

    public static function hashId(string $sessionId): string
    {
        return hash('sha256', $sessionId);
    }

    /**
     * @return string public id (ULID) of the new row
     */
    public function register(int $userId, string $sessionId, string $ip, string $userAgent): string
    {
        $now = DbTime::format($this->clock->now());
        $publicId = (string) new Ulid();
        $this->db->table('user_sessions')->insert([
            'public_id' => $publicId,
            'user_id' => $userId,
            'session_id_hash' => self::hashId($sessionId),
            'ip' => mb_substr($ip, 0, 45),
            'user_agent' => mb_substr($userAgent, 0, 255),
            'created_at' => $now,
            'last_seen_at' => $now,
        ]);

        return $publicId;
    }

    /**
     * Owner of an active (not revoked) session, or null. Also refreshes `last_seen_at` once a minute.
     */
    public function activeUserId(string $sessionId): ?int
    {
        $hash = self::hashId($sessionId);
        $rows = $this->db->select('SELECT user_id, last_seen_at FROM user_sessions WHERE session_id_hash = ? AND revoked_at IS NULL LIMIT 1', [$hash]);
        if ($rows === []) {
            return null;
        }
        $now = $this->clock->now();
        $seen = DbTime::parse($rows[0]['last_seen_at']);
        if ($seen === null || $now->getTimestamp() - $seen->getTimestamp() >= self::TOUCH_INTERVAL) {
            $this->db->execute('UPDATE user_sessions SET last_seen_at = ? WHERE session_id_hash = ?', [DbTime::format($now), $hash]);
        }

        return (int) $rows[0]['user_id'];
    }

    /**
     * Mark the session of the current browser as ended (its data is destroyed by the caller via `Session`).
     */
    public function end(string $sessionId): void
    {
        $this->db->execute('UPDATE user_sessions SET revoked_at = ? WHERE session_id_hash = ? AND revoked_at IS NULL', [DbTime::format($this->clock->now()), self::hashId($sessionId)]);
    }

    /**
     * Sign out one device of a user. False when the id is unknown, already revoked, belongs to someone else,
     * or is the browser making the request (use the normal sign-out for that one).
     */
    public function revoke(int $userId, string $publicId, string $currentSessionId): bool
    {
        if (!Ulid::isValid($publicId)) {
            return false;
        }
        $rows = $this->db->select('SELECT session_id_hash FROM user_sessions WHERE public_id = ? AND user_id = ? AND revoked_at IS NULL LIMIT 1', [$publicId, $userId]);
        if ($rows === [] || hash_equals(self::hashId($currentSessionId), (string) $rows[0]['session_id_hash'])) {
            return false;
        }
        $this->store->destroyHashed((string) $rows[0]['session_id_hash']);
        $this->db->execute('UPDATE user_sessions SET revoked_at = ? WHERE public_id = ? AND user_id = ?', [DbTime::format($this->clock->now()), $publicId, $userId]);

        return true;
    }

    /**
     * Sign a user out everywhere, optionally keeping the browser that is making the request.
     *
     * @return int number of sessions ended
     */
    public function revokeAll(int $userId, ?string $exceptSessionId = null): int
    {
        $except = $exceptSessionId === null ? null : self::hashId($exceptSessionId);
        $count = 0;
        foreach ($this->db->select('SELECT session_id_hash FROM user_sessions WHERE user_id = ? AND revoked_at IS NULL', [$userId]) as $row) {
            $hash = (string) $row['session_id_hash'];
            if ($hash === $except) {
                continue;
            }
            $this->store->destroyHashed($hash);
            $this->db->execute('UPDATE user_sessions SET revoked_at = ? WHERE session_id_hash = ?', [DbTime::format($this->clock->now()), $hash]);
            ++$count;
        }

        return $count;
    }

    /**
     * Active sessions of a user, newest activity first.
     *
     * @param int $idleSeconds sessions silent for longer than this are considered expired and hidden
     * @return list<array{public_id: string, ip: string, user_agent: string, created_at: DateTimeImmutable, last_seen_at: DateTimeImmutable, current: bool}>
     */
    public function list(int $userId, string $currentSessionId, int $idleSeconds): array
    {
        $cutoff = DbTime::format($this->clock->now()->modify(sprintf('-%d seconds', $idleSeconds + self::TOUCH_INTERVAL)));
        $current = self::hashId($currentSessionId);
        $out = [];
        $rows = $this->db->select(
            'SELECT public_id, session_id_hash, ip, user_agent, created_at, last_seen_at FROM user_sessions
             WHERE user_id = ? AND revoked_at IS NULL AND last_seen_at > ? ORDER BY last_seen_at DESC',
            [$userId, $cutoff],
        );
        foreach ($rows as $row) {
            $out[] = [
                'public_id' => (string) $row['public_id'],
                'ip' => (string) $row['ip'],
                'user_agent' => (string) $row['user_agent'],
                'created_at' => DbTime::parse($row['created_at']) ?? $this->clock->now(),
                'last_seen_at' => DbTime::parse($row['last_seen_at']) ?? $this->clock->now(),
                'current' => hash_equals($current, (string) $row['session_id_hash']),
            ];
        }

        return $out;
    }

    /**
     * Delete session rows revoked or silent for more than 30 days.
     */
    public function prune(): int
    {
        $cutoff = DbTime::format($this->clock->now()->modify('-30 days'));

        return $this->db->execute('DELETE FROM user_sessions WHERE revoked_at < ? OR last_seen_at < ?', [$cutoff, $cutoff]);
    }
}
