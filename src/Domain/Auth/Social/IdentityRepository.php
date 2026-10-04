<?php

declare(strict_types=1);

namespace App\Domain\Auth\Social;

use App\Integrations\OAuth\SocialProfile;
use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;
use DateTimeImmutable;
use PDOException;

/**
 * Persistence for `user_identities`. A provider account belongs to exactly one user, and a user has at
 * most one account per provider; both rules are enforced by unique keys, so concurrent requests cannot break them.
 */
final class IdentityRepository
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    public function find(string $provider, string $providerUserId): ?UserIdentity
    {
        $row = $this->db->table('user_identities')->where('provider', '=', $provider)->where('provider_user_id', '=', $providerUserId)->first();

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * @return list<UserIdentity>
     */
    public function forUser(int $userId): array
    {
        $rows = $this->db->select('SELECT * FROM user_identities WHERE user_id = ? ORDER BY id', [$userId]);

        return array_map($this->hydrate(...), $rows);
    }

    public function findForUser(int $userId, string $provider): ?UserIdentity
    {
        $row = $this->db->table('user_identities')->where('user_id', '=', $userId)->where('provider', '=', $provider)->first();

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Attach a provider account to a user. False when the account or the provider slot is already taken.
     */
    public function link(int $userId, SocialProfile $profile): bool
    {
        $now = DbTime::format($this->clock->now());
        try {
            $this->db->table('user_identities')->insert([
                'user_id' => $userId,
                'provider' => $profile->provider,
                'provider_user_id' => $profile->id,
                'email' => $profile->email === null ? null : mb_substr($profile->email, 0, 254),
                'display_name' => $profile->name === '' ? null : mb_substr($profile->name, 0, 150),
                'linked_at' => $now,
                'last_login_at' => $now,
            ]);
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) {
                return false;
            }
            throw $e;
        }

        return true;
    }

    public function unlink(int $userId, string $provider): bool
    {
        return $this->db->execute('DELETE FROM user_identities WHERE user_id = ? AND provider = ?', [$userId, $provider]) === 1;
    }

    /**
     * Remember the latest name and email the provider reported and the time of this sign-in.
     */
    public function touch(UserIdentity $identity, SocialProfile $profile): void
    {
        $this->db->execute(
            'UPDATE user_identities SET last_login_at = ?, email = ?, display_name = ? WHERE id = ?',
            [
                DbTime::format($this->clock->now()),
                $profile->email === null ? null : mb_substr($profile->email, 0, 254),
                $profile->name === '' ? $identity->displayName : mb_substr($profile->name, 0, 150),
                $identity->id,
            ],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): UserIdentity
    {
        return new UserIdentity(
            (int) $row['id'],
            (int) $row['user_id'],
            (string) $row['provider'],
            (string) $row['provider_user_id'],
            is_string($row['email']) ? $row['email'] : null,
            is_string($row['display_name']) ? $row['display_name'] : null,
            DbTime::parse($row['linked_at']) ?? new DateTimeImmutable('@0'),
        );
    }
}
