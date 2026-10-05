<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Admin\StaffAccess;
use App\Domain\Admin\StaffRole;
use App\Domain\Audit\AuditLog;
use App\Domain\User\User;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\Middleware\MiddlewareInterface;
use Closure;

/**
 * Requires a permission of the back office matrix (`config/admin_permissions.php`) for one route, e.g.
 * `[RequireStaffPermission::class, ['permission' => 'finance.manage']]`. A role without it gets 403 and the attempt is written to the audit
 * trail. Must run after `RequireStaff`.
 */
final class RequireStaffPermission implements MiddlewareInterface
{
    public function __construct(
        private readonly StaffAccess $staff,
        private readonly AuditLog $audit,
        private readonly string $permission,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $role = $request->attribute('staff_role');
        $user = $request->attribute('user');
        if (!$role instanceof StaffRole || !$user instanceof User) {
            throw new HttpException(404, 'Not found');
        }
        if (!$this->staff->allows($role, $this->permission)) {
            $this->audit->record('admin.denied', $user->id, 'permission', $this->permission, ['method' => $request->method, 'path' => $request->path, 'role' => $role->value]);
            throw new HttpException(403, 'Forbidden');
        }

        return $next($request);
    }
}
