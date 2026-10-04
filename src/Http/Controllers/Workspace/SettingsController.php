<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workspace;

use App\Domain\Workspace\WorkspaceService;
use App\Http\FormFlash;
use App\Http\WorkspaceRequest;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\Exception\HttpException;
use App\Kernel\Validation\Validator;
use App\Kernel\View\View;
use DateTimeZone;

/**
 * Workspace settings (name, time zone, language) and deleting the workspace.
 */
final class SettingsController
{
    /** @var array<string, string> */
    public const LOCALES = ['ru' => 'Русский'];

    public function __construct(
        private readonly View $view,
        private readonly Validator $validator,
        private readonly WorkspaceService $service,
        private readonly FormFlash $flash,
    ) {
    }

    public function show(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);

        return $this->view->response('workspace/settings.twig', [
            'workspace' => $context,
            'timezones' => $this->timezones(),
            'locales' => self::LOCALES,
        ]);
    }

    public function update(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $input = [
            'name' => WorkspaceRequest::text($request->input('name')),
            'timezone' => WorkspaceRequest::text($request->input('timezone')),
            'locale' => WorkspaceRequest::text($request->input('locale')),
        ];
        $errors = $this->validator->make($input, [
            'name' => 'required|string|min:2|max:100',
            'timezone' => 'required|string|timezone',
            'locale' => 'required|string|in:' . implode(',', array_keys(self::LOCALES)),
        ], ['name' => 'Название', 'timezone' => 'Часовой пояс', 'locale' => 'Язык'])->errors();
        if ($errors !== []) {
            $this->flash->invalid($input, $errors);

            return Response::redirect('/w/' . $context->workspacePublicId . '/settings');
        }
        if (!$this->service->update($context, $input['name'], $input['timezone'], $input['locale'])) {
            throw new HttpException(403, 'Forbidden');
        }
        $this->flash->toast('Настройки сохранены.');

        return Response::redirect('/w/' . $context->workspacePublicId . '/settings');
    }

    public function delete(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        if (WorkspaceRequest::text($request->input('confirm_name')) !== $context->workspaceName) {
            $this->flash->invalid([], ['delete' => ['Название введено неверно. Пространство не удалено.']]);

            return Response::redirect('/w/' . $context->workspacePublicId . '/settings#delete');
        }
        if (!$this->service->delete($context)) {
            throw new HttpException(403, 'Forbidden');
        }
        $this->flash->toast('Пространство «' . $context->workspaceName . '» удалено.');

        return Response::redirect('/app');
    }

    /**
     * @return array<string, string> time zone id => label, Russian zones first
     */
    private function timezones(): array
    {
        $out = [];
        foreach (DateTimeZone::listIdentifiers() as $id) {
            $offset = (new DateTimeZone($id))->getOffset(new \DateTimeImmutable('now', new DateTimeZone('UTC'))) / 3600;
            $out[$id] = sprintf('%s (UTC%s%s)', $id, $offset < 0 ? '−' : '+', rtrim(rtrim(number_format(abs($offset), 2, '.', ''), '0'), '.'));
        }

        return $out;
    }
}
