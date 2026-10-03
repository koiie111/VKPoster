<?php

declare(strict_types=1);

namespace App\Kernel\Middleware;

use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use Closure;

/**
 * One step of the request pipeline (PSR-15 in spirit, with our own Request/Response).
 * Call `$next($request)` to continue, or return a response to short-circuit.
 */
interface MiddlewareInterface
{
    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response;
}
