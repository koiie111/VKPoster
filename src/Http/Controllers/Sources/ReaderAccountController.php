<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sources;

use App\Domain\Source\ReaderAccounts;
use App\Domain\Source\SourceException;
use App\Http\FormFlash;
use App\Http\WorkspaceRequest;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;

/** Workspace owner account import; routes provide workspace permission and CSRF. */
final class ReaderAccountController
{
    public function __construct(private readonly ReaderAccounts $accounts, private readonly FormFlash $flash)
    {
    }

    public function create(Request $request): Response
    {
        $ctx = WorkspaceRequest::context($request);
        try {
            $this->accounts->create($ctx->workspaceId, 'Собственный аккаунт', $ctx->userId);
            $this->flash->toast('Аккаунт создан. Подключите авторизованную сессию Telegram.');
        } catch (SourceException $e) {
            $this->flash->toast($e->getMessage(), 'error');
        }
        return Response::redirect($this->back($request));
    }

    public function attach(Request $request, string $id): Response
    {
        $ctx = WorkspaceRequest::context($request);
        $telegramId = WorkspaceRequest::text($request->input('telegram_user_id'));
        try {
            if (preg_match('/^[1-9][0-9]{0,18}$/D', $telegramId) !== 1 || (string) (int) $telegramId !== $telegramId) {
                throw new SourceException('Укажите числовой ID пользователя Telegram.', 'telegram_user_id');
            }
            $this->accounts->attachSession($id, (int) $telegramId, WorkspaceRequest::text($request->input('session')), $ctx->userId, $ctx->workspaceId);
            $this->flash->toast('Сессия сохранена в зашифрованном виде. Reader проверит Telegram ID при подключении.');
        } catch (SourceException $e) {
            $this->flash->toast($e->getMessage(), 'error');
        }
        return Response::redirect($this->back($request));
    }

    public function status(Request $request, string $id): Response
    {
        $ctx = WorkspaceRequest::context($request);
        try {
            $this->accounts->changeStatus($id, WorkspaceRequest::text($request->input('status')), $ctx->userId, $ctx->workspaceId);
            $this->flash->toast('Состояние аккаунта обновлено.');
        } catch (SourceException $e) {
            $this->flash->toast($e->getMessage(), 'error');
        }
        return Response::redirect($this->back($request));
    }

    private function back(Request $request): string
    {
        return '/w/' . WorkspaceRequest::context($request)->workspacePublicId . '/sources#reader-account';
    }
}
