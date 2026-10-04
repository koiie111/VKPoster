<?php

declare(strict_types=1);

namespace App\Domain\Auth\Social;

use App\Domain\Audit\AuditLog;
use App\Domain\User\User;
use App\Domain\User\UserRepository;
use App\Domain\Workspace\WorkspaceService;
use App\Integrations\OAuth\SocialProfile;
use App\Kernel\Security\RateLimiter;
use App\Support\Clock;

/**
 * Matching a provider profile with our accounts. The rules (from the strictest to the most permissive):
 *
 * 1. A known provider account signs its owner in.
 * 2. A signed-in user may attach a provider account that nobody else owns.
 * 3. A provider-VERIFIED email that matches an existing account never signs in or merges automatically:
 *    the visitor must prove ownership with the account's password first (otherwise whoever controls a
 *    lookalike provider account could take over the account).
 * 4. Otherwise a new account is offered, after the visitor agrees to personal data processing.
 *
 * An email the provider does not vouch for is never stored on the user, so it cannot squat on an address
 * that its real owner may want to register with later.
 */
final class SocialAuthService
{
    public function __construct(
        private readonly IdentityRepository $identities,
        private readonly UserRepository $users,
        private readonly AuditLog $audit,
        private readonly RateLimiter $limiter,
        private readonly Clock $clock,
        private readonly WorkspaceService $workspaces,
        private readonly string $consentVersion,
    ) {
    }

    /**
     * Decide what to do with a profile. Linking (rule 2) happens here; creating an account (rule 4) is a
     * separate step, `createAccount()`, because it needs the visitor's consent.
     */
    public function resolve(SocialProfile $profile, ?User $current): SocialResult
    {
        $identity = $this->identities->find($profile->provider, $profile->id);
        if ($identity !== null) {
            if ($current !== null) {
                return new SocialResult($identity->userId === $current->id ? SocialStatus::AlreadyLinked : SocialStatus::BelongsToOther, $current);
            }
            $owner = $this->users->find($identity->userId);
            if ($owner === null) {
                return new SocialResult(SocialStatus::NewAccount);
            }
            if ($owner->isBlocked()) {
                return new SocialResult(SocialStatus::Blocked, $owner);
            }
            $this->identities->touch($identity, $profile);

            return new SocialResult(SocialStatus::SignedIn, $owner);
        }
        if ($current !== null) {
            if ($this->identities->findForUser($current->id, $profile->provider) !== null) {
                return new SocialResult(SocialStatus::ProviderSlotTaken, $current);
            }
            if (!$this->identities->link($current->id, $profile)) {
                return new SocialResult(SocialStatus::BelongsToOther, $current);
            }
            $this->audit->record('auth.social.linked', $current->id, 'user', (string) $current->id, ['provider' => $profile->provider]);

            return new SocialResult(SocialStatus::Linked, $current);
        }
        $email = $profile->trustedEmail();
        if ($email !== null && $this->users->findByEmail($email) !== null) {
            return new SocialResult(SocialStatus::EmailExists);
        }

        return new SocialResult(SocialStatus::NewAccount);
    }

    /**
     * Create the account for a profile that `resolve()` called a new one. Null when the provider account
     * or its email was taken in the meantime (a concurrent request): the caller restarts the flow.
     */
    public function createAccount(SocialProfile $profile, string $name): ?User
    {
        $email = $profile->trustedEmail();
        if ($email !== null && $this->users->findByEmail($email) !== null) {
            return null;
        }
        $user = $this->users->create([
            'email' => $email,
            'name' => $name,
            'password_hash' => null,
            'email_verified_at' => $email === null ? null : $this->clock->now(),
            'consent_version' => $this->consentVersion,
        ]);
        if ($user === null) {
            return null;
        }
        if (!$this->identities->link($user->id, $profile)) {
            $this->users->delete($user->id);

            return null;
        }
        $this->audit->record('auth.social.registered', $user->id, 'user', (string) $user->id, ['provider' => $profile->provider]);
        $this->workspaces->createPersonal($user);

        return $user;
    }

    /**
     * Attach the profile that was held back by rule 3, now that the visitor proved ownership of the
     * account by password. Only if the account's email is the very address the provider vouched for.
     */
    public function linkAfterPassword(User $user, SocialProfile $profile): bool
    {
        $email = $profile->trustedEmail();
        if ($email === null || $user->email === null || $email !== $user->email) {
            return false;
        }
        if ($this->identities->findForUser($user->id, $profile->provider) !== null || !$this->identities->link($user->id, $profile)) {
            return false;
        }
        $this->audit->record('auth.social.linked', $user->id, 'user', (string) $user->id, ['provider' => $profile->provider, 'via' => 'password']);

        return true;
    }

    /**
     * Detach a provider account. Refused when it would leave the user with no way to sign in.
     *
     * @return 'ok'|'last'|'missing'
     */
    public function unlink(User $user, string $provider): string
    {
        if ($this->identities->findForUser($user->id, $provider) === null) {
            return 'missing';
        }
        if ($this->methodCount($user) < 2) {
            return 'last';
        }
        $this->identities->unlink($user->id, $provider);
        $this->audit->record('auth.social.unlinked', $user->id, 'user', (string) $user->id, ['provider' => $provider]);

        return 'ok';
    }

    /**
     * Number of ways the user can sign in: each linked provider account, plus the password when the
     * account has both an email and a password.
     */
    public function methodCount(User $user): int
    {
        return count($this->identities->forUser($user->id)) + ($this->hasPasswordLogin($user) ? 1 : 0);
    }

    public function hasPasswordLogin(User $user): bool
    {
        return $user->email !== null && $user->passwordHash !== null;
    }

    /**
     * Telegram links are valid for 24 hours, so each hash is accepted once to stop replay of a captured link.
     */
    public function acceptTelegramHash(string $hash): bool
    {
        return $this->limiter->attempt('tg-auth:' . $hash, 1, 86400)->allowed;
    }
}
