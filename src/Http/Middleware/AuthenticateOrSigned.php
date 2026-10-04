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
 * For files that are also fetched by machines: a request carrying a `signature` goes through untouched (the
 * controller verifies it); every other request needs a signed-in, unblocked user, and guests get a plain 404
 * (no redirect to the login page, no hint that the file exists).
 *
 * Sets the request attribute `user` when someone is signed in.
 */
final class AuthenticateOrSigned implements MiddlewareInterface
{
    public function __construct(
        private readonly RequestContext $context,
        private readonly SessionRegistry $registry,
        private readonly UserRepository $users,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (is_string($request->query['signature'] ?? null)) {
            return $next($request);
        }
        $session = $this->context->session();
        $userId = $session?->get('auth.user_id');
        $user = null;
        if ($session !== null && is_int($userId) && $this->registry->activeUserId($session->id()) === $userId) {
            $user = $this->users->find($userId);
        }
        if ($user === null || $user->isBlocked()) {
            throw new HttpException(404, 'Not found');
        }
        $this->context->setUser($user);

        return $next($request->withAttribute('user', $user)->withAttribute('user_id', $user->id));
    }
}
