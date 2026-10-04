<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Kernel\Http\Request;
use App\Kernel\Http\RequestContext;
use App\Kernel\Http\Response;
use App\Kernel\Middleware\MiddlewareInterface;
use Closure;

/**
 * For sign-in and sign-up pages: people who are already signed in go to the application instead.
 */
final class Guest implements MiddlewareInterface
{
    public function __construct(private readonly RequestContext $context)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->context->session()?->has('auth.user_id') === true) {
            return Response::redirect('/app');
        }

        return $next($request);
    }
}
