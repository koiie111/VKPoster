<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;

/**
 * One-time tokens for email verification, password reset and email change.
 *
 * The raw token (32 random bytes, base64url) exists only in the email link; the database keeps its
 * SHA-256 hash, an expiry and a `used_at` mark. `consume()` is atomic, so a token works exactly once
 * even when two requests race. Issuing a new token of a type invalidates the older unused ones.
 */
final class AuthTokens
{
    public const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{43}$/';

    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    /**
     * @param array<string, scalar|null> $payload data to keep with the token
     * @return string the raw token (put it into a link, never store or log it)
     */
    public function issue(int $userId, TokenType $type, int $ttlSeconds, array $payload = []): string
    {
        $now = $this->clock->now();
        $raw = self::generate();
        $this->db->transaction(function (Connection $db) use ($userId, $type, $ttlSeconds, $payload, $now, $raw): void {
            $db->execute('UPDATE auth_tokens SET used_at = ? WHERE user_id = ? AND type = ? AND used_at IS NULL', [DbTime::format($now), $userId, $type->value]);
            $db->table('auth_tokens')->insert([
                'user_id' => $userId,
                'type' => $type->value,
                'token_hash' => hash('sha256', $raw),
                'payload_json' => $payload === [] ? null : json_encode($payload, JSON_THROW_ON_ERROR),
                'expires_at' => DbTime::format($now->modify(sprintf('+%d seconds', $ttlSeconds))),
                'created_at' => DbTime::format($now),
            ]);
        });

        return $raw;
    }

    /**
     * Check a token without using it (to decide which page to show).
     */
    public function peek(string $raw, TokenType $type): ?AuthToken
    {
        if (preg_match(self::TOKEN_PATTERN, $raw) !== 1) {
            return null;
        }
        $hash = hash('sha256', $raw);
        $rows = $this->db->select(
            'SELECT id, user_id, type, token_hash, payload_json FROM auth_tokens WHERE token_hash = ? AND type = ? AND used_at IS NULL AND expires_at > ? LIMIT 1',
            [$hash, $type->value, DbTime::format($this->clock->now())],
        );

        return $rows === [] ? null : $this->hydrate($rows[0], $hash);
    }

    /**
     * Use a token. Returns it only the first time, and only if it is unexpired and of the given type.
     */
    public function consume(string $raw, TokenType $type): ?AuthToken
    {
        $token = $this->peek($raw, $type);
        if ($token === null) {
            return null;
        }
        $claimed = $this->db->execute(
            'UPDATE auth_tokens SET used_at = ? WHERE id = ? AND used_at IS NULL AND expires_at > ?',
            [DbTime::format($this->clock->now()), $token->id, DbTime::format($this->clock->now())],
        );

        return $claimed === 1 ? $token : null;
    }

    public function revokeAll(int $userId, TokenType $type): void
    {
        $this->db->execute('UPDATE auth_tokens SET used_at = ? WHERE user_id = ? AND type = ? AND used_at IS NULL', [DbTime::format($this->clock->now()), $userId, $type->value]);
    }

    /**
     * Delete finished tokens (used or expired for more than a week).
     */
    public function prune(): int
    {
        return $this->db->execute(
            'DELETE FROM auth_tokens WHERE expires_at < ? OR used_at < ?',
            [DbTime::format($this->clock->now()->modify('-7 days')), DbTime::format($this->clock->now()->modify('-7 days'))],
        );
    }

    public static function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row, string $hash): ?AuthToken
    {
        if (!hash_equals((string) $row['token_hash'], $hash)) {
            return null;
        }
        $payload = is_string($row['payload_json']) ? json_decode($row['payload_json'], true) : [];

        return new AuthToken(
            (int) $row['id'],
            (int) $row['user_id'],
            TokenType::from((string) $row['type']),
            is_array($payload) ? $payload : [],
        );
    }
}
