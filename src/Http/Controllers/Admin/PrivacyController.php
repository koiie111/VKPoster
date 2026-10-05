<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\PersonalData;
use App\Domain\Audit\AuditLog;
use App\Domain\User\UserRepository;
use App\Http\Admin\AdminInput;
use App\Http\Admin\StepUp;
use App\Http\FormFlash;
use App\Http\WorkspaceRequest;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;

/**
 * Admin: requests about people's data under 152-FZ. Open a request, hand the person a copy of their data (JSON or ZIP), or delete the account
 * (anonymise it, keeping the financial records the law requires). Reading someone's data and deleting it are audited and ask for a fresh code.
 */
final class PrivacyController
{
    public function __construct(
        private readonly View $view,
        private readonly PersonalData $data,
        private readonly UserRepository $users,
        private readonly AuditLog $audit,
        private readonly FormFlash $flash,
        private readonly StepUp $stepUp,
    ) {
    }

    public function index(): Response
    {
        return $this->view->response('admin/privacy.twig', ['requests' => $this->data->requests(), 'types' => PersonalData::TYPES]);
    }

    public function open(Request $request): Response
    {
        $type = AdminInput::choice($request, 'type', array_keys(PersonalData::TYPES), 'export');
        $reference = AdminInput::text($request, 'user', 254);
        if ($reference === '') {
            $this->flash->toast('Укажите почту или номер человека.', 'error');

            return Response::redirect('/admin/privacy');
        }
        $this->data->open($reference, $type, AdminInput::text($request, 'note', 1000), WorkspaceRequest::user($request));
        $this->flash->toast('Запрос записан.');
        $back = $request->input('back');

        return Response::redirect(is_string($back) && preg_match('#^/admin/(users/\d{1,12}|privacy)$#', $back) === 1 ? $back : '/admin/privacy');
    }

    public function download(Request $request, string $publicId): Response
    {
        $staff = WorkspaceRequest::user($request);
        $row = $this->data->find($publicId) ?? throw new HttpException(404, 'Not found');
        if ($row['user_id'] === null || $this->users->find((int) $row['user_id']) === null) {
            $this->flash->toast('Аккаунта по этому запросу нет: выгружать нечего.', 'error');

            return Response::redirect('/admin/privacy');
        }
        $userId = (int) $row['user_id'];
        $export = $this->data->export($userId);
        $zip = AdminInput::choice($request, 'format', ['json', 'zip'], 'zip') === 'zip';
        $this->audit->record('admin.data_exported', $staff->id, 'user', (string) $userId, ['request' => $publicId, 'format' => $zip ? 'zip' : 'json']);
        if ($row['type'] === 'export') {
            $this->data->complete($publicId, 'done', $staff);
        }
        $body = $zip ? PersonalData::zip($export) : PersonalData::json($export);

        return (new Response(200, $body))
            ->withHeader('Content-Type', $zip ? 'application/zip' : 'application/json; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="data-user-' . $userId . ($zip ? '.zip' : '.json') . '"')
            ->withHeader('Cache-Control', 'no-store');
    }

    public function anonymize(Request $request, string $publicId): Response
    {
        $staff = WorkspaceRequest::user($request);
        $row = $this->data->find($publicId) ?? throw new HttpException(404, 'Not found');
        if (($denied = $this->stepUp->guard($request, $staff, '/admin/privacy')) !== null) {
            return $denied;
        }
        $target = $row['user_id'] === null ? null : $this->users->find((int) $row['user_id']);
        if ($target === null) {
            $this->flash->toast('Аккаунта по этому запросу нет.', 'error');

            return Response::redirect('/admin/privacy');
        }
        $error = $this->data->anonymize($target, $staff);
        if ($error !== null) {
            $this->flash->toast($error, 'error');

            return Response::redirect('/admin/privacy');
        }
        $this->data->complete($publicId, 'done', $staff);
        $this->flash->toast('Аккаунт анонимизирован. Финансовые записи сохранены.');

        return Response::redirect('/admin/privacy');
    }

    public function reject(Request $request, string $publicId): Response
    {
        $this->data->find($publicId) ?? throw new HttpException(404, 'Not found');
        $this->data->complete($publicId, 'rejected', WorkspaceRequest::user($request));
        $this->flash->toast('Запрос закрыт без выполнения.');

        return Response::redirect('/admin/privacy');
    }
}
