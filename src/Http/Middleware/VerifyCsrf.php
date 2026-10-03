<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\RequestContext;
use App\Kernel\Http\Response;
use App\Kernel\Http\Route;
use App\Kernel\Middleware\MiddlewareInterface;
use App\Kernel\Security\Csrf;
use Closure;

/**
 * Rejects state-changing requests (anything but GET/HEAD/OPTIONS) without a valid CSRF token and
 * same-origin headers with 419. Routes opt out with `->withoutCsrf()` (webhooks, token-auth API).
 */
final class VerifyCsrf implements MiddlewareInterface
{
    public function __construct(private readonly Csrf $csrf, private readonly RequestContext $context)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isReadMethod()) {
            return $next($request);
        }
        $route = $request->attribute('route');
        if ($route instanceof Route && !$route->requiresCsrf()) {
            return $next($request);
        }
        $session = $this->context->session();
        if ($session === null || !$this->csrf->isValid($request, $session)) {
            throw new HttpException(419, 'CSRF token mismatch');
        }

        return $next($request);
    }
}
