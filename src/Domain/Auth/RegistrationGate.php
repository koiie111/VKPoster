<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Settings\SiteSettings;
use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;

/**
 * Decides whether a new account may be created right now (the owner's setting: open, by invitation, closed) and manages the invitation
 * codes. A code is stored only as a SHA-256 hash; the plain code is shown once when it is made. Using a code is atomic, so two people cannot
 * both use the last use of it.
 */
final class RegistrationGate
{
    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly SiteSettings $site,
    ) {
    }

    /**
     * @return 'open'|'invite'|'closed'
     */
    public function mode(): string
    {
        return $this->site->registrationMode();
    }

    /**
     * Whether an account may be created now. For the invitation mode a valid code is needed.
     *
     * @return string|null the reason in the visitor's words, null when it is allowed
     */
    public function refusal(?string $code): ?string
    {
        return match ($this->mode()) {
            'closed' => 'Регистрация сейчас закрыта. Загляните позже или напишите нам.',
            'invite' => $this->valid($code) ? null : 'Регистрация идёт по приглашениям. Введите код приглашения.',
            default => null,
        };
    }

    public function valid(?string $code): bool
    {
        $code = $this->normalize($code);

        return $code !== '' && $this->find($code) !== null;
    }

    /**
     * Use a code once. Returns false when it is unknown, expired, revoked or used up.
     */
    public function consume(?string $code): bool
    {
        $code = $this->normalize($code);
        if ($code === '') {
            return false;
        }
        $now = DbTime::format($this->clock->now());

        return $this->db->execute(
            'UPDATE invite_codes SET uses = uses + 1 WHERE code_hash = ? AND revoked_at IS NULL AND uses < max_uses AND (expires_at IS NULL OR expires_at > ?)',
            [hash('sha256', $code), $now],
        ) === 1;
    }

    /**
     * Make a code. Returns the plain code (shown once).
     */
    public function create(string $note, int $maxUses, ?\DateTimeImmutable $expiresAt, ?int $actorId): string
    {
        $code = strtoupper(bin2hex(random_bytes(5)));
        $this->db->table('invite_codes')->insert([
            'code_hash' => hash('sha256', $code),
            'hint' => substr($code, -4),
            'note' => $note === '' ? null : mb_substr($note, 0, 150),
            'max_uses' => max(1, min($maxUses, 10000)),
            'expires_at' => $expiresAt === null ? null : DbTime::format($expiresAt),
            'created_by' => $actorId,
            'created_at' => DbTime::format($this->clock->now()),
        ]);

        return $code;
    }

    public function revoke(int $id): void
    {
        $this->db->execute('UPDATE invite_codes SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL', [DbTime::format($this->clock->now()), $id]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function codes(): array
    {
        return array_map(static function (array $r): array {
            $r['expires_at'] = DbTime::parse($r['expires_at']);
            $r['revoked_at'] = DbTime::parse($r['revoked_at']);
            $r['created_at'] = DbTime::parse($r['created_at']);

            return $r;
        }, $this->db->select('SELECT id, hint, note, max_uses, uses, expires_at, revoked_at, created_at FROM invite_codes ORDER BY id DESC LIMIT 100'));
    }

    private function normalize(?string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $code) ?? '');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function find(string $code): ?array
    {
        $rows = $this->db->select(
            'SELECT id FROM invite_codes WHERE code_hash = ? AND revoked_at IS NULL AND uses < max_uses AND (expires_at IS NULL OR expires_at > ?)',
            [hash('sha256', $code), DbTime::format($this->clock->now())],
        );

        return $rows === [] ? null : $rows[0];
    }
}
