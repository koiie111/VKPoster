<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Audit\AuditLog;
use App\Domain\User\User;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\Middleware\MiddlewareInterface;
use Closure;

/**
 * Safety net of the admin audit trail: every request that changes something (anything but GET/HEAD) is written as `admin.request` with
 * its route and the answer status, whatever the controller does. Controllers add the meaningful entries (`admin.user_blocked` with the
 * reason and before/after values); this one makes sure that a forgotten call cannot leave an action unrecorded. The body is never logged.
 */
final class AdminAuditTrail implements MiddlewareInterface
{
    public function __construct(private readonly AuditLog $audit)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $user = $request->attribute('user');
        if ($user instanceof User && !in_array($request->method, ['GET', 'HEAD'], true)) {
            $this->audit->record('admin.request', $user->id, 'route', $request->path, ['method' => $request->method, 'status' => $response->status]);
        }

        return $response;
    }
}
