<?php

declare(strict_types=1);

namespace App\Domain\Workspace;

use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;

/**
 * Finds and closes an invitation by the secret from the emailed link. The token is the capability here,
 * so no workspace context is needed (or available): the database keeps only its SHA-256 hash.
 */
final class InvitationLookup
{
    public const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{43}$/';

    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    public static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * An invitation that is neither accepted, revoked nor expired; null for anything else (also for malformed input).
     */
    public function findOpen(string $token): ?Invitation
    {
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            return null;
        }
        $hash = self::hash($token);
        $rows = $this->db->select(
            'SELECT * FROM invitations WHERE token_hash = ? AND accepted_at IS NULL AND revoked_at IS NULL AND expires_at > ? LIMIT 1',
            [$hash, DbTime::format($this->clock->now())],
        );
        if ($rows === [] || !hash_equals((string) $rows[0]['token_hash'], $hash)) {
            return null;
        }

        return InvitationRepository::hydrate($rows[0]);
    }

    /**
     * Mark the invitation used. True for exactly one caller, even when two requests race.
     */
    public function markAccepted(Invitation $invitation): bool
    {
        $now = DbTime::format($this->clock->now());

        return $this->db->execute(
            'UPDATE invitations SET accepted_at = ? WHERE id = ? AND accepted_at IS NULL AND revoked_at IS NULL AND expires_at > ?',
            [$now, $invitation->id, $now],
        ) === 1;
    }

    /**
     * Delete invitations that ended more than 30 days ago.
     */
    public function prune(): int
    {
        $limit = DbTime::format($this->clock->now()->modify('-30 days'));

        return $this->db->execute('DELETE FROM invitations WHERE expires_at < ? OR accepted_at < ? OR revoked_at < ?', [$limit, $limit, $limit]);
    }
}
