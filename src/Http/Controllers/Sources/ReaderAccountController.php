<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sources;

use App\Domain\Source\ReaderAccounts;
use App\Domain\Source\ReaderQr;
use App\Domain\Source\SourceException;
use App\Http\FormFlash;
use App\Http\WorkspaceRequest;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;

/** Workspace owner account import; routes provide workspace permission and CSRF. */
final class ReaderAccountController
{
    public function __construct(private readonly ReaderAccounts $accounts, private readonly ReaderQr $qr, private readonly View $view, private readonly FormFlash $flash)
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

    public function startQr(Request $request, string $id): Response
    {
        try {
            $this->qr->begin($id, WorkspaceRequest::context($request)->workspaceId);
        } catch (SourceException $e) {
            $this->flash->toast($e->getMessage(), 'error');
            return Response::redirect($this->back($request));
        }
        return Response::redirect($this->qrUrl($request, $id));
    }

    public function qr(Request $request, string $id): Response
    {
        $ctx = WorkspaceRequest::context($request);
        $status = $this->qr->view($id, $ctx->workspaceId);
        $account = $this->accounts->list($ctx->workspaceId)[0] ?? null;
        $submitted = ($request->query['submitted'] ?? null) === '1';
        $response = $this->view->response('workspace/sources/reader-qr.twig', ['account' => $account, 'qr' => $status, 'submitted' => $submitted, 'account_id' => $id, 'base' => '/w/' . $ctx->workspacePublicId . '/sources'])->withHeader('Cache-Control', 'no-store');
        return in_array($status['state'], ['waiting', 'qr'], true) || $submitted
            ? $response->withHeader('Refresh', '5; url=' . $this->qrUrl($request, $id))
            : $response;
    }

    public function qrImage(Request $request, string $id): Response
    {
        $view = $this->qr->view($id, WorkspaceRequest::context($request)->workspaceId);
        if ($view['state'] !== 'qr' || $view['image'] === null) {
            return new Response(404);
        }
        $bytes = base64_decode($view['image'], true);
        if (!is_string($bytes)) {
            return new Response(404);
        }
        return new Response(200, $bytes, ['Content-Type' => 'image/png', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function password(Request $request, string $id): Response
    {
        try {
            $input = $request->input('password');
            $this->qr->submitPassword($id, WorkspaceRequest::context($request)->workspaceId, is_string($input) ? $input : '');
            $this->flash->toast('Пароль передан для завершения подключения.');
        } catch (SourceException $e) {
            $this->flash->toast($e->getMessage(), 'error');
        }
        return Response::redirect($this->qrUrl($request, $id) . '?submitted=1');
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

    private function qrUrl(Request $request, string $id): string
    {
        return '/w/' . WorkspaceRequest::context($request)->workspacePublicId . '/sources/reader-account/' . $id . '/qr';
    }
}
