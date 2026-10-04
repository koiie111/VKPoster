<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Workspace\WorkspaceRepository;
use App\Domain\Workspace\WorkspaceService;
use App\Http\Middleware\ResolveWorkspace;
use App\Http\WorkspaceRequest;
use App\Kernel\Http\Request;
use App\Kernel\Http\RequestContext;
use App\Kernel\Http\Response;

/**
 * `/app`: sends the signed-in user to their workspace (the last one they used, else the first; a personal one is created if they have none).
 */
final class AppController
{
    public function __construct(
        private readonly WorkspaceRepository $workspaces,
        private readonly WorkspaceService $service,
        private readonly RequestContext $context,
    ) {
    }

    public function dashboard(Request $request): Response
    {
        $user = WorkspaceRequest::user($request);
        // This hop renders nothing, so a message flashed before it (for example after sign-in) must survive it.
        $this->context->session()?->reflash('_toasts');
        $last = $this->context->session()?->get(ResolveWorkspace::SESSION_KEY);
        if (is_string($last)) {
            $workspace = $this->workspaces->findByPublicId($last);
            if ($workspace !== null && $this->workspaces->membership($workspace->id, $user->id) !== null) {
                return Response::redirect('/w/' . $workspace->publicId);
            }
        }

        return Response::redirect('/w/' . $this->service->homeFor($user)->publicId);
    }
}
