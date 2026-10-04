<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;

/**
 * "Remember me" cookies of the form `selector:validator`.
 *
 * - The database keeps the selector (lookup key) and SHA-256 of the validator, never the validator.
 * - Every successful use rotates the validator and extends the lifetime.
 * - The previous validator stays valid for 60 seconds, because a browser may fire several requests
 *   with the same cookie at once. Presenting an older validator later means the cookie was stolen
 *   (or replayed): all remember tokens of that user are deleted and the caller ends all sessions.
 */
final class RememberMe
{
    public const GRACE_SECONDS = 60;

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly int $days = 30,
    ) {
    }

    public function lifetimeSeconds(): int
    {
        return $this->days * 86400;
    }

    /**
     * Create a remember token for a freshly signed-in user. Returns the cookie value.
     */
    public function issue(int $userId): string
    {
        $now = $this->clock->now();
        $selector = bin2hex(random_bytes(12));
        $validator = AuthTokens::generate();
        $this->db->table('auth_tokens')->insert([
            'user_id' => $userId,
            'type' => TokenType::Remember->value,
            'selector' => $selector,
            'token_hash' => hash('sha256', $validator),
            'expires_at' => DbTime::format($now->modify(sprintf('+%d days', $this->days))),
            'created_at' => DbTime::format($now),
        ]);

        return $selector . ':' . $validator;
    }

    /**
     * Validate a cookie and rotate it.
     *
     * @return array{user_id: int, cookie: ?string, stolen: bool}|null `cookie` is the replacement value
     *         (null inside the grace window); `stolen` is true when reuse of an old validator was detected
     *         (then user_id identifies the victim and the result must be treated as a failed login).
     */
    public function authenticate(string $cookie): ?array
    {
        if (preg_match('/^([0-9a-f]{24}):([A-Za-z0-9_-]{43})$/', $cookie, $m) !== 1) {
            return null;
        }
        [, $selector, $validator] = $m;
        $rows = $this->db->select(
            'SELECT id, user_id, token_hash, payload_json, expires_at, used_at FROM auth_tokens WHERE selector = ? AND type = ? LIMIT 1',
            [$selector, TokenType::Remember->value],
        );
        if ($rows === []) {
            return null;
        }
        $row = $rows[0];
        $now = $this->clock->now();
        $expires = DbTime::parse($row['expires_at']);
        if ($row['used_at'] !== null || $expires === null || $expires <= $now) {
            return null;
        }
        $userId = (int) $row['user_id'];
        $hash = hash('sha256', $validator);
        if (hash_equals((string) $row['token_hash'], $hash)) {
            $next = AuthTokens::generate();
            $payload = json_encode(['prev' => $hash, 'at' => $now->getTimestamp()], JSON_THROW_ON_ERROR);
            $rotated = $this->db->execute(
                'UPDATE auth_tokens SET token_hash = ?, payload_json = ?, expires_at = ? WHERE id = ? AND token_hash = ?',
                [hash('sha256', $next), $payload, DbTime::format($now->modify(sprintf('+%d days', $this->days))), (int) $row['id'], $hash],
            );
            if ($rotated !== 1) {
                return null; // lost a race with a parallel request that rotated first
            }

            return ['user_id' => $userId, 'cookie' => $selector . ':' . $next, 'stolen' => false];
        }
        $payload = is_string($row['payload_json']) ? json_decode($row['payload_json'], true) : null;
        if (is_array($payload) && isset($payload['prev'], $payload['at']) && is_string($payload['prev']) && is_int($payload['at'])
            && hash_equals($payload['prev'], $hash) && $now->getTimestamp() - $payload['at'] <= self::GRACE_SECONDS) {
            return ['user_id' => $userId, 'cookie' => null, 'stolen' => false];
        }
        $this->revokeAll($userId);

        return ['user_id' => $userId, 'cookie' => null, 'stolen' => true];
    }

    /**
     * Forget the device of this cookie (sign out).
     */
    public function revoke(string $cookie): void
    {
        if (preg_match('/^([0-9a-f]{24}):/', $cookie, $m) === 1) {
            $this->db->execute('DELETE FROM auth_tokens WHERE selector = ? AND type = ?', [$m[1], TokenType::Remember->value]);
        }
    }

    public function revokeAll(int $userId): void
    {
        $this->db->execute('DELETE FROM auth_tokens WHERE user_id = ? AND type = ?', [$userId, TokenType::Remember->value]);
    }
}
