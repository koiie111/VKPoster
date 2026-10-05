<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\AdminDirectory;
use App\Domain\Audit\AuditLog;
use App\Domain\Auth\SessionRegistry;
use App\Domain\User\User;
use App\Domain\User\UserRepository;
use App\Http\Auth\Impersonation;
use App\Http\FormFlash;
use App\Http\WorkspaceRequest;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;

/**
 * Admin: find a person, see their workspaces and recent actions, block or unblock them, and sign in as them for support.
 */
final class UsersController
{
    public function __construct(
        private readonly View $view,
        private readonly AdminDirectory $directory,
        private readonly UserRepository $users,
        private readonly SessionRegistry $sessions,
        private readonly Impersonation $impersonation,
        private readonly AuditLog $audit,
        private readonly FormFlash $flash,
    ) {
    }

    public function index(Request $request): Response
    {
        $query = WorkspaceRequest::text($request->input('q'));
        $page = $request->input('page');

        return $this->view->response('admin/users/index.twig', [
            'q' => $query,
            'result' => $this->directory->users($query, is_string($page) && ctype_digit($page) ? (int) $page : 1),
            'base' => '/admin/users' . ($query === '' ? '' : '?q=' . rawurlencode($query)),
        ]);
    }

    public function show(string $id): Response
    {
        $row = $this->directory->user((int) $id) ?? throw new HttpException(404, 'Not found');

        return $this->view->response('admin/users/show.twig', [
            'person' => $row,
            'workspaces' => $this->directory->userWorkspaces((int) $id),
            'activity' => $this->directory->userActivity((int) $id),
        ]);
    }

    public function block(Request $request, string $id): Response
    {
        $staff = WorkspaceRequest::user($request);
        $target = $this->target((int) $id);
        if ($target->id === $staff->id || $target->isSuperadmin) {
            $this->flash->toast('Этого человека заблокировать нельзя: он сотрудник.', 'error');

            return Response::redirect('/admin/users/' . $target->id);
        }
        $this->users->setStatus($target->id, User::STATUS_BLOCKED);
        // A blocked person is signed out everywhere at once.
        $this->sessions->revokeAll($target->id);
        $this->audit->record('admin.user_blocked', $staff->id, 'user', (string) $target->id);
        $this->flash->toast('Аккаунт заблокирован, все его сессии завершены.');

        return Response::redirect('/admin/users/' . $target->id);
    }

    public function unblock(Request $request, string $id): Response
    {
        $staff = WorkspaceRequest::user($request);
        $target = $this->target((int) $id);
        $this->users->setStatus($target->id, User::STATUS_ACTIVE);
        $this->audit->record('admin.user_unblocked', $staff->id, 'user', (string) $target->id);
        $this->flash->toast('Аккаунт разблокирован.');

        return Response::redirect('/admin/users/' . $target->id);
    }

    public function impersonate(Request $request, string $id): Response
    {
        $staff = WorkspaceRequest::user($request);
        $target = $this->target((int) $id);
        if ($target->id === $staff->id || $target->isSuperadmin || $target->isBlocked()) {
            $this->flash->toast('Под этим аккаунтом войти нельзя: это сотрудник или заблокированный человек.', 'error');

            return Response::redirect('/admin/users/' . $target->id);
        }
        $this->impersonation->start($this->flash->session(), $staff, $target);

        return Response::redirect('/app');
    }

    private function target(int $id): User
    {
        return $this->users->find($id) ?? throw new HttpException(404, 'Not found');
    }
}
