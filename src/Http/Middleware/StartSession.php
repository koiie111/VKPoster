<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Kernel\Config;
use App\Kernel\Http\Request;
use App\Kernel\Http\RequestContext;
use App\Kernel\Http\Response;
use App\Kernel\Middleware\MiddlewareInterface;
use App\Kernel\Session\Session;
use App\Kernel\Session\SessionStore;
use App\Support\Clock;
use Closure;

/**
 * Loads the session from the `__Host-sid` cookie (plain `sid` on http in local dev), exposes it through
 * `RequestContext`, saves it after the response is built and sets the cookie for new or regenerated ids.
 * Cookie: HttpOnly, SameSite=Lax, Secure whenever the app runs on https.
 */
final class StartSession implements MiddlewareInterface
{
    public function __construct(
        private readonly SessionStore $store,
        private readonly Clock $clock,
        private readonly RequestContext $context,
        private readonly Config $config,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $secure = $this->config->bool('session.secure');
        $cookieName = $secure ? '__Host-sid' : 'sid';
        $session = new Session(
            $this->store,
            $this->clock,
            $this->config->int('session.idle_ttl', 7200),
            $this->config->int('session.absolute_ttl', 2592000),
        );
        $session->start($request->cookie($cookieName));
        $this->context->setSession($session);

        $response = $next($request->withAttribute('session', $session));
        $session->save();

        if ($session->wasDestroyed()) {
            return $response->withCookie($cookieName, '', -1, $secure);
        }
        if ($session->needsCookie()) {
            return $response->withCookie($cookieName, $session->id(), 0, $secure);
        }

        return $response;
    }
}
