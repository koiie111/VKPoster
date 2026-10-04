<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workspace;

use App\Domain\Workspace\WorkspaceService;
use App\Http\FormFlash;
use App\Http\WorkspaceRequest;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\Validation\Validator;
use App\Kernel\View\View;

/**
 * Workspace home page and creating an additional workspace.
 */
final class WorkspaceController
{
    public function __construct(
        private readonly View $view,
        private readonly Validator $validator,
        private readonly WorkspaceService $service,
        private readonly FormFlash $flash,
    ) {
    }

    public function home(Request $request): Response
    {
        return $this->view->response('workspace/home.twig', [
            'user' => WorkspaceRequest::user($request),
            'workspace' => WorkspaceRequest::context($request),
        ]);
    }

    public function create(): Response
    {
        return $this->view->response('workspace/new.twig');
    }

    public function store(Request $request): Response
    {
        $user = WorkspaceRequest::user($request);
        $name = WorkspaceRequest::text($request->input('name'));
        $errors = $this->validator->make(['name' => $name], ['name' => 'required|string|min:2|max:100'], ['name' => 'Название'])->errors();
        $workspace = $errors === [] ? $this->service->create($user, $name) : null;
        if ($errors === [] && $workspace === null) {
            $errors['name'] = ['Можно создать не больше ' . WorkspaceService::OWNED_LIMIT . ' пространств. Удалите ненужное или напишите нам.'];
        }
        if ($workspace === null) {
            $this->flash->invalid(['name' => $name], $errors);

            return Response::redirect('/workspaces/new');
        }
        $this->flash->toast('Пространство «' . $workspace->name . '» создано.');

        return Response::redirect('/w/' . $workspace->publicId);
    }
}
