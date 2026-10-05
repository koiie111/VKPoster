<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Kernel\Http\Request;
use App\Kernel\Http\RequestContext;
use App\Kernel\Http\Response;
use App\Kernel\Middleware\MiddlewareInterface;
use App\Support\Clock;
use Closure;

/**
 * The admin area needs a fresh second factor: a TOTP (or recovery) code entered on `/admin/unlock` within the last `TTL` seconds.
 * This is what keeps a "remember me" session, which skips the code at sign-in, from opening the back office.
 */
final class RequireAdminUnlock implements MiddlewareInterface
{
    public const SESSION_KEY = 'admin.unlocked_at';
    public const TTL = 28800;

    public function __construct(private readonly RequestContext $context, private readonly Clock $clock)
    {
    }

    public static function isUnlocked(?\App\Kernel\Session\Session $session, Clock $clock): bool
    {
        $at = $session?->get(self::SESSION_KEY);

        return is_int($at) && $at > $clock->now()->getTimestamp() - self::TTL;
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!self::isUnlocked($this->context->session(), $this->clock)) {
            if ($request->method === 'GET') {
                $this->context->session()?->set('admin.intended', $request->path);
            }

            return Response::redirect('/admin/unlock');
        }

        return $next($request);
    }
}
