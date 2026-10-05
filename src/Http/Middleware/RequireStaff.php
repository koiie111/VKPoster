<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Admin\StaffAccess;
use App\Domain\User\User;
use App\Http\Auth\Impersonation;
use App\Kernel\Config;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\RequestContext;
use App\Kernel\Http\Response;
use App\Kernel\Middleware\MiddlewareInterface;
use App\Kernel\View\View;
use App\Support\IpRange;
use Closure;

/**
 * Door of `/admin`. Anybody who is not staff (the owner or a member of `staff_members`) gets a plain 404 (the area does not admit it
 * exists), and so does every address outside `ADMIN_IP_ALLOWLIST` when that is set. A staff member without two-factor protection is
 * stopped on a page that explains how to switch it on: staff accounts can see everyone's data, so a password alone is not enough.
 * A session that is "acting as a customer" never reaches the admin area. Must run after `Authenticate`.
 * Sets the request attribute `staff_role` (`StaffRole`).
 */
final class RequireStaff implements MiddlewareInterface
{
    /** Set only by `/dev/login-as` (local development): the session of a seeded staff account without two-factor. */
    public const DEV_SESSION_KEY = 'admin.dev_session';

    public function __construct(
        private readonly View $view,
        private readonly Impersonation $impersonation,
        private readonly RequestContext $context,
        private readonly Config $config,
        private readonly StaffAccess $staff,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->attribute('user');
        $role = $user instanceof User ? $this->staff->roleOf($user) : null;
        if (!$user instanceof User || $role === null || $this->impersonation->active()) {
            throw new HttpException(404, 'Not found');
        }
        $allowed = array_values(array_filter(array_map('strval', $this->config->array('admin.ip_allowlist')), static fn (string $v): bool => $v !== ''));
        if ($allowed !== [] && !IpRange::containsAny($request->ip(), $allowed)) {
            throw new HttpException(404, 'Not found');
        }
        if (!$user->hasTwoFactor() && !self::isDevSession($this->context, $this->config)) {
            return $this->view->response('admin/two_factor_required.twig', [], 403);
        }

        return $next($request->withAttribute('staff_role', $role));
    }

    /**
     * The seeded staff account of local development, which has no second factor.
     */
    public static function isDevSession(RequestContext $context, Config $config): bool
    {
        return $context->session()?->get(self::DEV_SESSION_KEY) === true && !$config->isProduction() && $config->bool('auth.dev_login');
    }
}
