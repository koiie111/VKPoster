<?php

declare(strict_types=1);

namespace App\Http\Auth;

use App\Domain\Audit\AuditLog;
use App\Domain\Auth\LoginService;
use App\Domain\Auth\RememberMe;
use App\Domain\Auth\SessionRegistry;
use App\Domain\User\User;
use App\Domain\User\UserRepository;
use App\Kernel\Config;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\Session\Session;
use App\Support\Clock;

/**
 * Turns domain decisions into browser state: signing a user into the session (new session id, new CSRF
 * token, device row, journal, optional remember-me cookie), the half-signed-in state while the second
 * factor is pending, and signing out.
 */
final class SessionAuth
{
    public const PENDING_TTL = 600;

    public function __construct(
        private readonly SessionRegistry $registry,
        private readonly RememberMe $remember,
        private readonly UserRepository $users,
        private readonly LoginService $login,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
        private readonly Config $config,
    ) {
    }

    public function rememberCookieName(): string
    {
        return $this->config->bool('session.secure') ? '__Host-remember' : 'remember';
    }

    /**
     * Sign the user into this browser session.
     *
     * @param bool $issueRemember create a new remember-me token (false when rotating an existing cookie)
     * @return string|null remember-me cookie value to set, if one was issued
     */
    public function signIn(Request $request, Session $session, User $user, bool $issueRemember): ?string
    {
        // A new id on every privilege change defeats session fixation; a new CSRF token goes with it.
        $session->regenerate();
        $session->forget('auth.pending');
        $session->forget('_csrf');
        $session->set('auth.user_id', $user->id);
        $session->set('auth.at', $this->clock->now()->getTimestamp());
        $this->registry->register($user->id, $session->id(), $request->ip(), $request->header('user-agent') ?? '');
        $this->login->journal($user->id, 'success', $request->ip(), $request->header('user-agent') ?? '');
        $this->audit->record('auth.login', $user->id, 'user', (string) $user->id);

        return $issueRemember ? $this->remember->issue($user->id) : null;
    }

    /**
     * Password accepted, second factor still missing: remember who is trying, for 10 minutes.
     */
    public function beginTwoFactor(Session $session, User $user, bool $remember): void
    {
        $session->set('auth.pending', [
            'user_id' => $user->id,
            'remember' => $remember,
            'until' => $this->clock->now()->getTimestamp() + self::PENDING_TTL,
        ]);
    }

    /**
     * @return array{user: User, remember: bool}|null
     */
    public function pendingTwoFactor(Session $session): ?array
    {
        $pending = $session->get('auth.pending');
        if (!is_array($pending) || !is_int($pending['user_id'] ?? null) || !is_int($pending['until'] ?? null)) {
            return null;
        }
        if ($pending['until'] < $this->clock->now()->getTimestamp()) {
            $session->forget('auth.pending');

            return null;
        }
        $user = $this->users->find($pending['user_id']);

        return $user === null || !$user->hasTwoFactor() ? null : ['user' => $user, 'remember' => ($pending['remember'] ?? false) === true];
    }

    /**
     * End this browser's session and forget its remember-me cookie.
     */
    public function signOut(Request $request, Session $session): void
    {
        $userId = $session->get('auth.user_id');
        $this->registry->end($session->id());
        $cookie = $request->cookie($this->rememberCookieName());
        if ($cookie !== null) {
            $this->remember->revoke($cookie);
        }
        if (is_int($userId)) {
            $this->audit->record('auth.logout', $userId, 'user', (string) $userId);
        }
        $session->invalidate();
    }

    public function withRememberCookie(Response $response, string $value): Response
    {
        return $response->withCookie($this->rememberCookieName(), $value, $this->remember->lifetimeSeconds(), $this->config->bool('session.secure'));
    }

    public function withoutRememberCookie(Response $response): Response
    {
        return $response->withCookie($this->rememberCookieName(), '', -1, $this->config->bool('session.secure'));
    }
}
