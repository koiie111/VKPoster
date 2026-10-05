<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Auth\SessionRegistry;
use App\Domain\User\UserRepository;
use App\Kernel\Http\Request;
use App\Kernel\Http\RequestContext;
use App\Kernel\Http\Response;
use App\Kernel\Middleware\MiddlewareInterface;
use Closure;

/**
 * For public pages that look slightly different to a signed-in visitor (the landing's "Открыть сервис" button): finds the user
 * the same way `Authenticate` does, but never redirects and never ends a session. A guest simply stays a guest.
 */
final class OptionalAuthenticate implements MiddlewareInterface
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
        if ($session !== null && is_int($userId) && $this->registry->activeUserId($session->id()) === $userId) {
            $user = $this->users->find($userId);
            if ($user !== null && !$user->isBlocked()) {
                $this->context->setUser($user);
            }
        }

        return $next($request);
    }
}
