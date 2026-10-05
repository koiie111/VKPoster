<?php

declare(strict_types=1);

namespace App\Domain\User;

use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;
use DateTimeImmutable;
use PDOException;

/**
 * Persistence for `users`. Emails are stored lower-cased; every query is a prepared statement.
 */
final class UserRepository
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function find(int $id): ?User
    {
        $row = $this->db->table('users')->where('id', '=', $id)->first();

        return $row === null ? null : $this->hydrate($row);
    }

    public function findByEmail(string $email): ?User
    {
        $row = $this->db->table('users')->where('email', '=', self::normalizeEmail($email))->first();

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Insert a user. Returns null when the email is already taken (also under a concurrent insert).
     *
     * @param array{email: ?string, name: string, password_hash: ?string, email_verified_at?: ?DateTimeImmutable, consent_version?: ?string, is_superadmin?: bool, timezone?: string} $data
     */
    public function create(array $data): ?User
    {
        $now = DbTime::format($this->clock->now());
        $verified = $data['email_verified_at'] ?? null;
        try {
            $id = $this->db->table('users')->insert([
                'email' => $data['email'] === null ? null : self::normalizeEmail($data['email']),
                'email_verified_at' => $verified === null ? null : DbTime::format($verified),
                'password_hash' => $data['password_hash'],
                'password_changed_at' => $data['password_hash'] === null ? null : $now,
                'name' => $data['name'],
                'timezone' => $data['timezone'] ?? 'Europe/Moscow',
                'is_superadmin' => ($data['is_superadmin'] ?? false) ? 1 : 0,
                'consent_version' => $data['consent_version'] ?? null,
                'consent_at' => isset($data['consent_version']) ? $now : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) {
                return null;
            }
            throw $e;
        }

        if (isset($data['consent_version'])) {
            $this->db->table('user_consents')->insert(['user_id' => (int) $id, 'version' => $data['consent_version'], 'ip' => null, 'accepted_at' => $now]);
        }

        return $this->find((int) $id);
    }

    /**
     * Remember that the person accepted a version of the legal documents (the user row keeps the latest, `user_consents` the history).
     */
    public function recordConsent(int $id, string $version, ?string $ip): void
    {
        $now = DbTime::format($this->clock->now());
        $this->db->table('users')->where('id', '=', $id)->update(['consent_version' => $version, 'consent_at' => $now, 'updated_at' => $now]);
        $this->db->table('user_consents')->insert(['user_id' => $id, 'version' => $version, 'ip' => $ip, 'accepted_at' => $now]);
    }

    public function markVerified(int $id): void
    {
        $now = DbTime::format($this->clock->now());
        $this->db->execute('UPDATE users SET email_verified_at = COALESCE(email_verified_at, ?), updated_at = ? WHERE id = ?', [$now, $now, $id]);
    }

    public function setPassword(int $id, string $hash): void
    {
        $now = DbTime::format($this->clock->now());
        $this->db->table('users')->where('id', '=', $id)->update(['password_hash' => $hash, 'password_changed_at' => $now, 'updated_at' => $now]);
    }

    /**
     * Replace the hash without counting it as a password change (transparent rehash after login).
     */
    public function refreshHash(int $id, string $hash): void
    {
        $this->db->table('users')->where('id', '=', $id)->update(['password_hash' => $hash]);
    }

    /**
     * Change the sign-in email; the new address counts as verified because the caller confirmed it.
     * Returns false when the address belongs to someone else.
     */
    public function changeEmail(int $id, string $email): bool
    {
        $now = DbTime::format($this->clock->now());
        try {
            $this->db->table('users')->where('id', '=', $id)->update([
                'email' => self::normalizeEmail($email),
                'email_verified_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) {
                return false;
            }
            throw $e;
        }

        return true;
    }

    public function emailTaken(string $email): bool
    {
        return $this->db->table('users')->where('email', '=', self::normalizeEmail($email))->exists();
    }

    public function setPendingTotp(int $id, string $secretEnc): void
    {
        $this->db->table('users')->where('id', '=', $id)->update([
            'totp_secret_enc' => $secretEnc,
            'totp_enabled_at' => null,
            'totp_last_step' => null,
            'updated_at' => DbTime::format($this->clock->now()),
        ]);
    }

    public function enableTotp(int $id, int $step): void
    {
        $now = DbTime::format($this->clock->now());
        $this->db->table('users')->where('id', '=', $id)->update(['totp_enabled_at' => $now, 'totp_last_step' => $step, 'updated_at' => $now]);
    }

    public function clearTotp(int $id): void
    {
        $this->db->table('users')->where('id', '=', $id)->update([
            'totp_secret_enc' => null,
            'totp_enabled_at' => null,
            'totp_last_step' => null,
            'updated_at' => DbTime::format($this->clock->now()),
        ]);
    }

    /**
     * Atomically accept a TOTP step. True only for the first use of a step newer than the last accepted
     * one, so a code (or an older one) can never be replayed.
     */
    public function claimTotpStep(int $id, int $step): bool
    {
        return $this->db->execute(
            'UPDATE users SET totp_last_step = ? WHERE id = ? AND (totp_last_step IS NULL OR totp_last_step < ?)',
            [$step, $id, $step],
        ) === 1;
    }

    /**
     * Remove a user row (used only to roll back a half-finished sign-up; its dependants cascade).
     */
    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM users WHERE id = ?', [$id]);
    }

    public function setSuperadmin(int $id, bool $value): void
    {
        $this->db->table('users')->where('id', '=', $id)->update(['is_superadmin' => $value ? 1 : 0, 'updated_at' => DbTime::format($this->clock->now())]);
    }

    public function setStatus(int $id, string $status): void
    {
        $this->db->table('users')->where('id', '=', $id)->update(['status' => $status, 'updated_at' => DbTime::format($this->clock->now())]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): User
    {
        return new User(
            (int) $row['id'],
            is_string($row['email']) ? $row['email'] : null,
            DbTime::parse($row['email_verified_at']),
            is_string($row['password_hash']) ? $row['password_hash'] : null,
            (string) $row['name'],
            (string) $row['locale'],
            (string) $row['timezone'],
            is_string($row['totp_secret_enc']) ? $row['totp_secret_enc'] : null,
            DbTime::parse($row['totp_enabled_at']),
            (int) $row['is_superadmin'] === 1,
            (string) $row['status'],
            DbTime::parse($row['created_at']) ?? new DateTimeImmutable('@0'),
            is_string($row['consent_version'] ?? null) ? $row['consent_version'] : null,
        );
    }
}
