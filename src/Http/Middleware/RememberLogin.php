<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Audit\AuditLog;
use App\Domain\Auth\RememberMe;
use App\Domain\Auth\SessionRegistry;
use App\Domain\User\UserRepository;
use App\Http\Auth\SessionAuth;
use App\Kernel\Http\Request;
use App\Kernel\Http\RequestContext;
use App\Kernel\Http\Response;
use App\Kernel\Middleware\MiddlewareInterface;
use Closure;

/**
 * Signs a returning visitor in from the "remember me" cookie when the session has no user.
 *
 * A valid cookie starts a fresh session and is rotated. A cookie that presents an already-replaced
 * validator is treated as stolen: all remember-me tokens and all sessions of that user are revoked.
 * Unknown or expired cookies are simply removed.
 */
final class RememberLogin implements MiddlewareInterface
{
    public function __construct(
        private readonly RequestContext $context,
        private readonly RememberMe $remember,
        private readonly SessionAuth $auth,
        private readonly UserRepository $users,
        private readonly SessionRegistry $sessions,
        private readonly AuditLog $audit,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $session = $this->context->session();
        $cookie = $request->cookie($this->auth->rememberCookieName());
        if ($session === null || $cookie === null || $session->has('auth.user_id')) {
            return $next($request);
        }
        $result = $this->remember->authenticate($cookie);
        if ($result === null) {
            return $this->auth->withoutRememberCookie($next($request));
        }
        if ($result['stolen']) {
            $this->sessions->revokeAll($result['user_id']);
            $this->audit->record('auth.remember.theft_detected', $result['user_id'], 'user', (string) $result['user_id']);

            return $this->auth->withoutRememberCookie($next($request));
        }
        $user = $this->users->find($result['user_id']);
        if ($user === null || $user->isBlocked()) {
            return $this->auth->withoutRememberCookie($next($request));
        }
        $this->auth->signIn($request, $session, $user, false);
        $response = $next($request);

        return $result['cookie'] === null ? $response : $this->auth->withRememberCookie($response, $result['cookie']);
    }
}
