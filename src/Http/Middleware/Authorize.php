<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Workspace\Permissions;
use App\Domain\Workspace\WorkspaceContext;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\Middleware\MiddlewareInterface;
use Closure;

/**
 * Requires a permission from the matrix (`config/permissions.php`) in the current workspace, e.g.
 * `[Authorize::class, ['permission' => 'members.manage']]`. Answers 403 when the member's role lacks it.
 * Must run after `ResolveWorkspace`; without a resolved workspace it fails closed with 404.
 */
final class Authorize implements MiddlewareInterface
{
    public function __construct(
        private readonly Permissions $permissions,
        private readonly string $permission,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $workspace = $request->attribute('workspace');
        if (!$workspace instanceof WorkspaceContext) {
            throw new HttpException(404, 'Not found');
        }
        if (!$this->permissions->allows($workspace->role, $this->permission)) {
            throw new HttpException(403, 'Forbidden');
        }

        return $next($request);
    }
}
