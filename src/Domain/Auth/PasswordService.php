<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Audit\AuditLog;
use App\Domain\User\User;
use App\Domain\User\UserRepository;
use App\Kernel\Security\PasswordHasher;
use App\Kernel\Security\RateLimiter;

/**
 * Forgotten-password flow and password change. After any password change every other session and all
 * remember-me tokens are revoked and the owner gets a notification email.
 */
final class PasswordService
{
    public const RESET_TTL = 3600;

    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordHasher $hasher,
        private readonly AuthTokens $tokens,
        private readonly SessionRegistry $sessions,
        private readonly RememberMe $remember,
        private readonly AuthMailer $mailer,
        private readonly RateLimiter $limiter,
        private readonly AuditLog $audit,
    ) {
    }

    /**
     * Send a reset link if the address has an account. The caller shows the same answer either way.
     */
    public function requestReset(string $email): void
    {
        $email = UserRepository::normalizeEmail($email);
        if (!$this->limiter->attempt('reset-mail:' . hash('sha256', $email), 3, 3600)->allowed) {
            return;
        }
        $user = $this->users->findByEmail($email);
        if ($user === null || $user->isBlocked()) {
            return;
        }
        $this->mailer->passwordReset($user, $this->tokens->issue($user->id, TokenType::PasswordReset, self::RESET_TTL));
        $this->audit->record('auth.password.reset_requested', $user->id, 'user', (string) $user->id);
    }

    public function isValidResetToken(string $token): bool
    {
        return $this->tokens->peek($token, TokenType::PasswordReset) !== null;
    }

    /**
     * Set a new password with a reset link. The caller has already checked the password against `PasswordPolicy`.
     * Returns the user, or null when the token is bad, expired or already used.
     */
    public function reset(string $token, string $newPassword): ?User
    {
        $consumed = $this->tokens->consume($token, TokenType::PasswordReset);
        $user = $consumed === null ? null : $this->users->find($consumed->userId);
        if ($user === null) {
            return null;
        }
        $this->users->setPassword($user->id, $this->hasher->hash($newPassword));
        // Receiving the link proves control of the mailbox.
        $this->users->markVerified($user->id);
        $this->endAllSessions($user->id, null);
        $this->mailer->passwordChanged($user);
        $this->audit->record('auth.password.reset', $user->id, 'user', (string) $user->id);

        return $user;
    }

    /**
     * Change the password while signed in. Requires the current password (5 tries per 10 minutes).
     *
     * @return 'ok'|'wrong_password'|'throttled'
     */
    public function change(User $user, string $currentPassword, string $newPassword, string $currentSessionId): string
    {
        if (!$this->limiter->attempt('pw-change:' . $user->id, 5, 600)->allowed) {
            return 'throttled';
        }
        if ($user->passwordHash === null || !$this->hasher->verify($currentPassword, $user->passwordHash)) {
            return 'wrong_password';
        }
        $this->users->setPassword($user->id, $this->hasher->hash($newPassword));
        $this->endAllSessions($user->id, $currentSessionId);
        $this->mailer->passwordChanged($user);
        $this->audit->record('auth.password.changed', $user->id, 'user', (string) $user->id);

        return 'ok';
    }

    /**
     * Give an account that has no password (created through social sign-in) its first one. The caller has
     * checked the policy and, for accounts with 2FA, the second factor. Other devices are signed out.
     *
     * @return bool false when the account already has a password
     */
    public function setInitial(User $user, string $newPassword, string $currentSessionId): bool
    {
        if ($user->passwordHash !== null) {
            return false;
        }
        $this->users->setPassword($user->id, $this->hasher->hash($newPassword));
        $this->endAllSessions($user->id, $currentSessionId);
        $this->mailer->passwordChanged($user);
        $this->audit->record('auth.password.set', $user->id, 'user', (string) $user->id);

        return true;
    }

    /**
     * Check a password for a sensitive action (email change, disabling 2FA), with the same attempt limit.
     *
     * @return 'ok'|'wrong_password'|'throttled'
     */
    public function confirmPassword(User $user, string $password): string
    {
        if (!$this->limiter->attempt('pw-change:' . $user->id, 5, 600)->allowed) {
            return 'throttled';
        }

        return $user->passwordHash !== null && $this->hasher->verify($password, $user->passwordHash) ? 'ok' : 'wrong_password';
    }

    private function endAllSessions(int $userId, ?string $keepSessionId): void
    {
        $this->sessions->revokeAll($userId, $keepSessionId);
        $this->remember->revokeAll($userId);
    }
}
