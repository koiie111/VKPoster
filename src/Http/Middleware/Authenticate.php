<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Admin\StaffAccess;
use App\Domain\Auth\SessionRegistry;
use App\Domain\User\UserRepository;
use App\Http\Auth\Impersonation;
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
        private readonly Impersonation $impersonation,
        private readonly StaffAccess $staffAccess,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $session = $this->context->session();
        $user = null;
        // While support acts as a customer, the device row belongs to the staff member: that is who must still be signed in.
        $staffId = $session === null ? null : $this->impersonation->staffId($session);
        $userId = $session?->get('auth.user_id');
        if ($session !== null && is_int($userId) && $this->registry->activeUserId($session->id()) === ($staffId ?? $userId)) {
            $user = $this->users->find($userId);
            if ($staffId !== null && !$this->isActiveStaff($staffId)) {
                $user = null;
            }
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

    private function isActiveStaff(int $id): bool
    {
        $staff = $this->users->find($id);

        return $staff !== null && $this->staffAccess->isStaff($staff);
    }
}
