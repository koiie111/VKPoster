<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Admin\StaffAccess;
use App\Domain\Settings\SiteSettings;
use App\Domain\User\UserRepository;
use App\Kernel\Http\Request;
use App\Kernel\Http\RequestContext;
use App\Kernel\Http\Response;
use App\Kernel\Middleware\MiddlewareInterface;
use App\Kernel\View\View;
use Closure;

/**
 * Maintenance mode (the owner's switch in the admin area). While it is on, everybody except signed-in staff gets a "we are doing maintenance"
 * page with status 503. What the machines need keeps working: the health check, payment and bot notifications, static files and media, the
 * sign-in pages (so staff can get in), the status page and the legal documents. Runs after the session is loaded.
 */
final class Maintenance implements MiddlewareInterface
{
    private const OPEN_PREFIXES = ['/healthz', '/webhooks/', '/assets/', '/media/', '/theme.css', '/login', '/logout', '/auth/', '/status', '/legal/', '/unsubscribe/', '/robots.txt', '/dev/'];

    public function __construct(
        private readonly SiteSettings $site,
        private readonly RequestContext $context,
        private readonly UserRepository $users,
        private readonly StaffAccess $staff,
        private readonly View $view,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->site->maintenanceOn()) {
            return $next($request);
        }
        foreach (self::OPEN_PREFIXES as $prefix) {
            if ($request->path === rtrim($prefix, '/') || str_starts_with($request->path, $prefix)) {
                return $next($request);
            }
        }
        $userId = $this->context->session()?->get('auth.user_id');
        $user = is_int($userId) ? $this->users->find($userId) : null;
        if ($user !== null && $this->staff->isStaff($user)) {
            return $next($request);
        }
        $message = $this->site->maintenanceMessage();
        if ($request->wantsJson() || str_starts_with($request->path, '/api/')) {
            return Response::json(['error' => 'maintenance', 'message' => $message], 503)->withHeader('Retry-After', '600');
        }

        return $this->view->response('errors/maintenance.twig', ['message' => $message], 503)->withHeader('Retry-After', '600');
    }
}
