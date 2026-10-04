<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\User\User;
use App\Domain\User\UserRepository;
use App\Kernel\Database\Connection;
use App\Kernel\Security\PasswordHasher;
use App\Kernel\Security\RateLimiter;
use App\Support\Clock;
use App\Support\DbTime;
use DateTimeImmutable;

/**
 * Checks sign-in credentials and second factors. Knows nothing about sessions or cookies
 * (`App\Http\Auth\SessionAuth` turns a successful result into a signed-in browser).
 *
 * Protections: per-account lock with growing delay (`LoginThrottle`), identical work and answer for
 * unknown emails (a dummy hash is verified), transparent rehash of old password hashes, a warning email
 * after 10 failures, and a separate attempt limit for the second factor.
 */
final class LoginService
{
    private static ?string $dummyHash = null;

    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordHasher $hasher,
        private readonly LoginThrottle $throttle,
        private readonly TwoFactorService $twoFactor,
        private readonly RateLimiter $limiter,
        private readonly AuthMailer $mailer,
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    public function attempt(string $email, string $password, string $ip, string $userAgent): LoginResult
    {
        $email = UserRepository::normalizeEmail($email);
        $wait = $this->throttle->retryAfter($email);
        if ($wait > 0) {
            return new LoginResult(LoginStatus::Locked, null, $wait);
        }
        $user = $this->users->findByEmail($email);
        // Verify against a dummy hash for unknown emails and password-less accounts: same cost, same answer.
        $matches = $this->hasher->verify($password, $user->passwordHash ?? $this->dummyHash());
        if ($user === null || $user->passwordHash === null || !$matches) {
            $failures = $this->throttle->fail($email);
            if ($user !== null) {
                $this->journal($user->id, 'bad_password', $ip, $userAgent);
                if ($failures === LoginThrottle::NOTIFY_AT) {
                    $this->mailer->lockoutWarning($user);
                }
            }

            return new LoginResult(LoginStatus::Invalid);
        }
        $this->throttle->clear($email);
        if ($user->isBlocked()) {
            $this->journal($user->id, 'blocked', $ip, $userAgent);

            return new LoginResult(LoginStatus::Blocked, $user);
        }
        if ($this->hasher->needsRehash($user->passwordHash)) {
            $this->users->refreshHash($user->id, $this->hasher->hash($password));
        }

        return new LoginResult($user->hasTwoFactor() ? LoginStatus::NeedsTwoFactor : LoginStatus::Success, $user);
    }

    /**
     * Check the second factor (TOTP or recovery code): at most 5 tries per 10 minutes per account.
     *
     * @return 'ok'|'invalid'|'throttled'
     */
    public function checkSecondFactor(User $user, string $code, string $ip, string $userAgent): string
    {
        if (!$this->limiter->attempt('2fa:' . $user->id, 5, 600)->allowed) {
            return 'throttled';
        }
        if ($this->twoFactor->verifyAny($user, $code)) {
            $this->limiter->clear('2fa:' . $user->id);

            return 'ok';
        }
        $this->journal($user->id, 'bad_2fa', $ip, $userAgent);

        return 'invalid';
    }

    /**
     * Write a line of the sign-in journal shown on the security page.
     *
     * @param string $outcome success|bad_password|bad_2fa|blocked
     */
    public function journal(int $userId, string $outcome, string $ip, string $userAgent): void
    {
        $this->db->table('login_attempts')->insert([
            'user_id' => $userId,
            'outcome' => $outcome,
            'ip' => mb_substr($ip, 0, 45),
            'user_agent' => mb_substr($userAgent, 0, 255),
            'created_at' => DbTime::format($this->clock->now()),
        ]);
    }

    /**
     * @return list<array{outcome: string, ip: string, user_agent: string, at: DateTimeImmutable}>
     */
    public function recent(int $userId, int $limit = 15): array
    {
        $rows = $this->db->select(
            'SELECT outcome, ip, user_agent, created_at FROM login_attempts WHERE user_id = ? ORDER BY id DESC LIMIT ' . max(1, min(100, $limit)),
            [$userId],
        );

        return array_map(fn (array $row): array => [
            'outcome' => (string) $row['outcome'],
            'ip' => (string) $row['ip'],
            'user_agent' => (string) $row['user_agent'],
            'at' => DbTime::parse($row['created_at']) ?? $this->clock->now(),
        ], $rows);
    }

    public function pruneJournal(): int
    {
        return $this->db->execute('DELETE FROM login_attempts WHERE created_at < ?', [DbTime::format($this->clock->now()->modify('-90 days'))]);
    }

    private function dummyHash(): string
    {
        return self::$dummyHash ??= $this->hasher->hash(bin2hex(random_bytes(16)));
    }
}
