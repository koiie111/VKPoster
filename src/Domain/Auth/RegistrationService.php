<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Audit\AuditLog;
use App\Domain\User\User;
use App\Domain\User\UserRepository;
use App\Kernel\Security\PasswordHasher;
use App\Kernel\Security\RateLimiter;

/**
 * Sign-up and email confirmation.
 *
 * `register()` behaves identically for new and existing addresses (same work, same outcome for the
 * visitor), so the form cannot be used to find out who has an account: a new address gets a
 * confirmation link, an existing one gets an "you already have an account" email instead.
 */
final class RegistrationService
{
    public const VERIFY_TTL = 86400;

    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordHasher $hasher,
        private readonly AuthTokens $tokens,
        private readonly AuthMailer $mailer,
        private readonly RateLimiter $limiter,
        private readonly AuditLog $audit,
        private readonly string $consentVersion,
    ) {
    }

    public function register(string $email, string $name, string $password): void
    {
        $email = UserRepository::normalizeEmail($email);
        // Always hash, even when the address is taken, so response time does not depend on it.
        $hash = $this->hasher->hash($password);
        $user = $this->users->findByEmail($email) === null
            ? $this->users->create(['email' => $email, 'name' => trim($name), 'password_hash' => $hash, 'consent_version' => $this->consentVersion])
            : null;

        if ($user === null) {
            // At most 3 notices per hour per address, otherwise the form would be a mail bomb.
            if ($this->limiter->attempt('register-notice:' . hash('sha256', $email), 3, 3600)->allowed) {
                $this->mailer->accountExists($email);
            }

            return;
        }
        $this->audit->record('auth.register', $user->id, 'user', (string) $user->id);
        $this->sendVerification($user);
    }

    /**
     * (Re)send the confirmation link. Limited to 3 per hour per user.
     *
     * @return bool false when the limit is reached or there is nothing to confirm
     */
    public function sendVerification(User $user): bool
    {
        if ($user->email === null || $user->isVerified()) {
            return false;
        }
        if (!$this->limiter->attempt('verify-mail:' . $user->id, 3, 3600)->allowed) {
            return false;
        }
        $token = $this->tokens->issue($user->id, TokenType::EmailVerify, self::VERIFY_TTL);
        $this->mailer->verifyEmail($user->email, $user->name, $token);

        return true;
    }

    /**
     * Whether a confirmation link is still usable (decides which page to show).
     */
    public function isValidLink(string $token): bool
    {
        return $this->tokens->peek($token, TokenType::EmailVerify) !== null;
    }

    /**
     * Use a confirmation link. Returns the confirmed user, or null for a bad, expired or used token.
     */
    public function confirm(string $token): ?User
    {
        $consumed = $this->tokens->consume($token, TokenType::EmailVerify);
        if ($consumed === null) {
            return null;
        }
        $this->users->markVerified($consumed->userId);
        $this->audit->record('auth.email.verified', $consumed->userId, 'user', (string) $consumed->userId);

        return $this->users->find($consumed->userId);
    }
}
