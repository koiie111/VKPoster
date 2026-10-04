<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\User\User;
use App\Domain\Workspace\WorkspaceContext;
use App\Domain\Workspace\WorkspaceRepository;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\RequestContext;
use App\Kernel\Http\Response;
use App\Kernel\Middleware\MiddlewareInterface;
use Closure;

/**
 * Turns the `{workspaceId}` route parameter (a ULID) into a `WorkspaceContext` for the signed-in user.
 * A workspace that does not exist and a workspace the user does not belong to give the same 404, so
 * nobody can find out which workspace ids exist. Must run after `Authenticate`.
 *
 * Sets the request attribute `workspace` and remembers the workspace as the person's last used one.
 */
final class ResolveWorkspace implements MiddlewareInterface
{
    public const SESSION_KEY = 'workspace.last';

    public function __construct(
        private readonly WorkspaceRepository $workspaces,
        private readonly RequestContext $context,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->attribute('user');
        $params = $request->attribute('route_params');
        $publicId = is_array($params) ? ($params['workspaceId'] ?? null) : null;
        if (!$user instanceof User || !is_string($publicId)) {
            throw new HttpException(404, 'Not found');
        }
        $workspace = $this->workspaces->findByPublicId($publicId);
        $membership = $workspace === null ? null : $this->workspaces->membership($workspace->id, $user->id);
        if ($workspace === null || $membership === null) {
            throw new HttpException(404, 'Not found');
        }
        $context = WorkspaceContext::from($workspace, $membership);
        $this->context->setWorkspace($context);
        $this->context->session()?->set(self::SESSION_KEY, $workspace->publicId);

        return $next($request->withAttribute('workspace', $context));
    }
}
