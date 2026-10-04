<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Audit\AuditLog;
use App\Domain\User\User;
use App\Domain\User\UserRepository;
use App\Kernel\Security\RateLimiter;

/**
 * Changing the sign-in email: a confirmation link goes to the NEW address, the OLD address is warned
 * (it may be an account takeover), and the switch happens only when the new address confirms.
 * Asking for an address that already has an account looks the same to the user, so the form
 * cannot be used to find out who is registered.
 */
final class EmailChangeService
{
    public const TTL = 86400;

    public function __construct(
        private readonly UserRepository $users,
        private readonly AuthTokens $tokens,
        private readonly AuthMailer $mailer,
        private readonly RateLimiter $limiter,
        private readonly AuditLog $audit,
    ) {
    }

    public function request(User $user, string $newEmail): void
    {
        $newEmail = UserRepository::normalizeEmail($newEmail);
        if ($user->email === null || $newEmail === $user->email) {
            return;
        }
        if (!$this->limiter->attempt('email-change:' . $user->id, 5, 3600)->allowed) {
            return;
        }
        if ($this->users->emailTaken($newEmail)) {
            $this->mailer->accountExists($newEmail);
        } else {
            $this->mailer->emailChangeConfirm($newEmail, $user, $this->tokens->issue($user->id, TokenType::EmailChange, self::TTL, ['email' => $newEmail]));
        }
        $this->mailer->emailChangeRequested($user, $newEmail);
        $this->audit->record('auth.email.change_requested', $user->id, 'user', (string) $user->id);
    }

    /**
     * New address of a still-valid link, for the confirmation page.
     */
    public function pendingEmail(string $token): ?string
    {
        $peeked = $this->tokens->peek($token, TokenType::EmailChange);
        $email = $peeked?->payload['email'] ?? null;

        return is_string($email) ? $email : null;
    }

    /**
     * Apply the change. Null when the link is bad or used, or when the address was taken in the meantime.
     */
    public function confirm(string $token): ?User
    {
        $consumed = $this->tokens->consume($token, TokenType::EmailChange);
        $newEmail = $consumed?->payload['email'] ?? null;
        $user = $consumed === null ? null : $this->users->find($consumed->userId);
        if ($user === null || !is_string($newEmail) || $user->email === null) {
            return null;
        }
        $oldEmail = $user->email;
        if (!$this->users->changeEmail($user->id, $newEmail)) {
            return null;
        }
        $this->mailer->emailChanged($oldEmail, $newEmail, $user);
        $this->audit->record('auth.email.changed', $user->id, 'user', (string) $user->id);

        return $this->users->find($user->id);
    }
}
