<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Analytics\Analytics;
use App\Domain\Analytics\FirstTouch;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\Middleware\MiddlewareInterface;
use App\Kernel\Session\Session;
use Closure;

/**
 * On the public entry pages (landing, sign-up, sign-in): remembers where the visitor came from (`FirstTouch`) and counts one `visit`
 * event per session. Crawlers and link checkers are not counted. Reading only; the page answers as usual.
 */
final class TrackVisit implements MiddlewareInterface
{
    public function __construct(private readonly FirstTouch $touch, private readonly Analytics $analytics)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->attribute('session');
        if ($request->method === 'GET' && $session instanceof Session && !$this->isRobot($request->header('user-agent') ?? '')) {
            $first = $this->touch->capture($session, $request);
            if ($first !== null) {
                $this->analytics->track('visit', null, null, ['source' => $first['utm_source'] !== '' ? $first['utm_source'] : ($first['referrer'] !== '' ? $first['referrer'] : 'direct')], null, $first['visitor_id']);
            }
        }

        return $next($request);
    }

    private function isRobot(string $userAgent): bool
    {
        return $userAgent === '' || preg_match('/bot|crawl|spider|slurp|curl|wget|python|monitor|uptime|headless|preview/i', $userAgent) === 1;
    }
}
