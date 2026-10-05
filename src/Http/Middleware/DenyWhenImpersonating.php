<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Auth\Impersonation;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\Middleware\MiddlewareInterface;
use Closure;

/**
 * Closes the actions that support must never take on a customer's behalf: billing, the security of the account (password, email, two-factor,
 * devices), and deleting or handing over a workspace. Answers 403 while the session acts as a customer.
 */
final class DenyWhenImpersonating implements MiddlewareInterface
{
    public function __construct(private readonly Impersonation $impersonation)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->impersonation->active()) {
            throw new HttpException(403, 'Not available while signed in as a customer');
        }

        return $next($request);
    }
}
