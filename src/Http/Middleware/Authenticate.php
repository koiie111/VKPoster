<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\RequestContext;
use App\Kernel\Http\Response;
use App\Kernel\Middleware\MiddlewareInterface;
use Closure;

/**
 * Requires a signed-in user (`auth.user_id` in the session). Guests get a redirect to `/login`
 * (401 for JSON clients). The user model and login flow arrive in stage 02.
 */
final class Authenticate implements MiddlewareInterface
{
    public function __construct(private readonly RequestContext $context)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $userId = $this->context->session()?->get('auth.user_id');
        if (!is_string($userId) && !is_int($userId)) {
            if ($request->wantsJson()) {
                throw new HttpException(401, 'Unauthenticated');
            }

            return Response::redirect('/login');
        }

        return $next($request->withAttribute('user_id', $userId));
    }
}
