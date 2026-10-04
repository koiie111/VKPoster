<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Auth\SessionRegistry;
use App\Domain\User\UserRepository;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\RequestContext;
use App\Kernel\Http\Response;
use App\Kernel\Middleware\MiddlewareInterface;
use Closure;

/**
 * Requires a signed-in user. The session must name a user (`auth.user_id`), the device must still be
 * listed as active in `user_sessions` (revoking a device takes effect on its next request), and the
 * account must not be blocked. Guests get a redirect to `/login` (401 for JSON clients).
 *
 * Sets the request attributes `user` (`App\Domain\User\User`) and `user_id`.
 */
final class Authenticate implements MiddlewareInterface
{
    public function __construct(
        private readonly RequestContext $context,
        private readonly SessionRegistry $registry,
        private readonly UserRepository $users,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $session = $this->context->session();
        $userId = $session?->get('auth.user_id');
        $user = null;
        if ($session !== null && is_int($userId) && $this->registry->activeUserId($session->id()) === $userId) {
            $user = $this->users->find($userId);
        }
        if ($user === null || $user->isBlocked()) {
            if ($session !== null && $userId !== null) {
                // A revoked device or blocked account: drop the stale session entirely.
                $session->invalidate();
            } elseif ($session !== null && $request->method === 'GET' && !$request->wantsJson()) {
                // Remember where the visitor wanted to go; the login form sends them back (relative URLs only).
                $session->set('auth.intended', $request->path);
            }
            if ($request->wantsJson()) {
                throw new HttpException(401, 'Unauthenticated');
            }

            return Response::redirect('/login');
        }
        $this->context->setUser($user);

        return $next($request->withAttribute('user', $user)->withAttribute('user_id', $user->id));
    }
}
