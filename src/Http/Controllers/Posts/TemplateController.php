<?php

declare(strict_types=1);

namespace App\Http\Controllers\Posts;

use App\Domain\Audit\AuditLog;
use App\Domain\Post\PostException;
use App\Domain\Post\PostService;
use App\Domain\Post\PostTemplateRepository;
use App\Domain\Workspace\Permissions;
use App\Http\FormFlash;
use App\Http\WorkspaceRequest;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;
use App\Support\RuDates;
use DateTimeZone;

/**
 * Post templates: save the editor's current content under a name, list them, create a post from one (the editor opens with
 * `?template=`), delete. Everyone who may write drafts may save a template; deleting is for editors and up or the template's author.
 */
final class TemplateController
{
    public function __construct(
        private readonly View $view,
        private readonly FormFlash $flash,
        private readonly PostTemplateRepository $templates,
        private readonly PostService $service,
        private readonly Permissions $permissions,
        private readonly AuditLog $audit,
    ) {
    }

    public function index(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $zone = new DateTimeZone($context->timezone);
        $canManage = $this->permissions->allows($context->role, 'posts.publish');
        $rows = array_map(static fn (array $t): array => [
            'id' => $t['id'],
            'name' => $t['name'],
            'created' => RuDates::dayMonth($t['created_at']->setTimezone($zone)) . ' ' . $t['created_at']->setTimezone($zone)->format('Y'),
            'can_delete' => $canManage || $t['created_by'] === $context->userId,
        ], $this->templates->all($context));

        return $this->view->response('workspace/posts/templates.twig', ['workspace' => $context, 'templates' => $rows, 'base' => '/w/' . $context->workspacePublicId]);
    }

    /**
     * Save what is in the editor as a template. The whole editor form is submitted here, so the template is exactly what is on screen.
     */
    public function store(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $name = trim(WorkspaceRequest::text($request->input('template_name')));
        if (mb_strlen($name) < 1 || mb_strlen($name) > 100) {
            $this->flash->toast('Назовите шаблон: от 1 до 100 символов.', 'error');

            return Response::redirect($this->back($request, $context));
        }
        $form = PostForm::fromRequest($request);
        try {
            // The same checks as for a draft (channels and files must exist and be allowed), but nothing is written but the template.
            $this->service->problemsFor($context, $form->draft);
        } catch (PostException $e) {
            $this->flash->toast($e->getMessage(), 'error');

            return Response::redirect($this->back($request, $context));
        }
        $id = $this->templates->create($context, $name, $form->draft);
        $this->audit->record('template.created', $context->userId, 'template', $id, ['name' => $name], $context->workspaceId);
        $this->flash->toast('Шаблон «' . $name . '» сохранён. Найдёте его в разделе «Шаблоны».');

        return Response::redirect('/w/' . $context->workspacePublicId . '/templates');
    }

    public function delete(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $params = $request->attribute('route_params');
        $id = is_array($params) && is_string($params['templateId'] ?? null) ? $params['templateId'] : '';
        $template = $this->templates->find($context, $id) ?? throw new HttpException(404, 'Not found');
        if (!$this->permissions->allows($context->role, 'posts.publish') && $template['created_by'] !== $context->userId) {
            throw new HttpException(403, 'Forbidden');
        }
        $this->templates->delete($context, $id);
        $this->audit->record('template.deleted', $context->userId, 'template', $template['id'], ['name' => $template['name']], $context->workspaceId);
        $this->flash->toast('Шаблон «' . $template['name'] . '» удалён.');

        return Response::redirect('/w/' . $context->workspacePublicId . '/templates');
    }

    private function back(Request $request, \App\Domain\Workspace\WorkspaceContext $context): string
    {
        return '/w/' . $context->workspacePublicId . '/posts/new';
    }
}
