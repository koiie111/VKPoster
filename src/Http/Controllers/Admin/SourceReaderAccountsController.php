<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Source\ReaderAccounts;
use App\Domain\Source\SourceException;
use App\Http\Admin\StepUp;
use App\Http\FormFlash;
use App\Http\WorkspaceRequest;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;

/** Staff management of service reader identities; customer identities are managed in their workspaces. */
final class SourceReaderAccountsController
{
    public function __construct(private readonly View $view, private readonly ReaderAccounts $accounts, private readonly StepUp $stepUp, private readonly FormFlash $flash)
    {
    }

    public function index(): Response
    {
        return $this->view->response('admin/source-reader-accounts.twig', ['accounts' => $this->accounts->serviceList()]);
    }

    public function create(Request $request): Response
    {
        $staff = WorkspaceRequest::user($request);
        if (($denied = $this->stepUp->guard($request, $staff, '/admin/source-reader-accounts')) !== null) {
            return $denied;
        }
        try {
            $this->accounts->create(null, WorkspaceRequest::text($request->input('label')), $staff->id);
            $this->flash->toast('Аккаунт создан. Подключите сессию Telegram перед назначением источников.');
        } catch (SourceException $e) {
            $this->flash->toast($e->getMessage(), 'error');
        }
        return Response::redirect('/admin/source-reader-accounts');
    }

    public function status(Request $request, string $id): Response
    {
        $staff = WorkspaceRequest::user($request);
        if (($denied = $this->stepUp->guard($request, $staff, '/admin/source-reader-accounts')) !== null) {
            return $denied;
        }
        try {
            $this->accounts->changeStatus($id, WorkspaceRequest::text($request->input('status')), $staff->id);
            $this->flash->toast('Состояние аккаунта обновлено.');
        } catch (SourceException $e) {
            $this->flash->toast($e->getMessage(), 'error');
        }
        return Response::redirect('/admin/source-reader-accounts');
    }

    public function attach(Request $request, string $id): Response
    {
        $staff = WorkspaceRequest::user($request);
        if (($denied = $this->stepUp->guard($request, $staff, '/admin/source-reader-accounts')) !== null) {
            return $denied;
        }
        $telegramId = WorkspaceRequest::text($request->input('telegram_user_id'));
        $session = WorkspaceRequest::text($request->input('session'));
        try {
            if (preg_match('/^[1-9][0-9]{0,18}$/D', $telegramId) !== 1 || (string) (int) $telegramId !== $telegramId) {
                throw new SourceException('Укажите числовой ID пользователя Telegram.', 'telegram_user_id');
            }
            $this->accounts->attachSession($id, (int) $telegramId, $session, $staff->id);
            $this->flash->toast('Сессия сохранена в зашифрованном виде. Reader проверит Telegram ID при подключении.');
        } catch (SourceException $e) {
            $this->flash->toast($e->getMessage(), 'error');
        }
        return Response::redirect('/admin/source-reader-accounts');
    }

    public function assign(Request $request): Response
    {
        $staff = WorkspaceRequest::user($request);
        if (($denied = $this->stepUp->guard($request, $staff, '/admin/source-reader-accounts')) !== null) {
            return $denied;
        }
        try {
            $this->accounts->assignServiceSource(WorkspaceRequest::text($request->input('source_id')), WorkspaceRequest::text($request->input('account_id')), $staff->id);
            $this->flash->toast('Источник назначен служебному аккаунту.');
        } catch (SourceException $e) {
            $this->flash->toast($e->getMessage(), 'error');
        }
        return Response::redirect('/admin/source-reader-accounts');
    }
}
