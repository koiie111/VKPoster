<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Kernel\Config;
use App\Kernel\Http\Request;
use App\Kernel\Http\RequestContext;
use App\Kernel\Http\Response;
use App\Kernel\Middleware\MiddlewareInterface;
use App\Kernel\Security\Csp;
use Closure;

/**
 * Adds the browser security headers to every response: CSP with a per-request nonce, nosniff,
 * referrer and permissions policies, framing ban, and HSTS in production.
 */
final class SecurityHeaders implements MiddlewareInterface
{
    public function __construct(
        private readonly Csp $csp,
        private readonly RequestContext $context,
        private readonly Config $config,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request)
            ->withHeader('Content-Security-Policy', $this->csp->header($this->context->nonce(), $this->context->cspExtras()))
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
            ->withHeader('Cross-Origin-Opener-Policy', 'same-origin');
        if ($this->config->isProduction()) {
            $response = $response->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        if ($response->header('Cache-Control') === null) {
            $response = $response->withHeader('Cache-Control', 'no-store');
        }

        return $response;
    }
}
