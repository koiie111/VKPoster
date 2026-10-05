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
 * A separate, shorter idle timeout (`IDLE`) locks the area again after 30 minutes without a request: the clock moves forward on each one.
 */
final class RequireAdminUnlock implements MiddlewareInterface
{
    public const SESSION_KEY = 'admin.unlocked_at';
    public const LAST_SEEN_KEY = 'admin.last_seen_at';
    public const TTL = 28800;
    public const IDLE = 1800;

    public function __construct(private readonly RequestContext $context, private readonly Clock $clock)
    {
    }

    public static function isUnlocked(?\App\Kernel\Session\Session $session, Clock $clock): bool
    {
        $now = $clock->now()->getTimestamp();
        $at = $session?->get(self::SESSION_KEY);
        $seen = $session?->get(self::LAST_SEEN_KEY);

        return is_int($at) && $at > $now - self::TTL && (!is_int($seen) || $seen > $now - self::IDLE);
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!self::isUnlocked($this->context->session(), $this->clock)) {
            if ($request->method === 'GET') {
                $this->context->session()?->set('admin.intended', $request->path);
            }

            return Response::redirect('/admin/unlock');
        }

        $this->context->session()?->set(self::LAST_SEEN_KEY, $this->clock->now()->getTimestamp());

        return $next($request);
    }
}
