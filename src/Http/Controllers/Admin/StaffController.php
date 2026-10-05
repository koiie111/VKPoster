<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\StaffAccess;
use App\Domain\Admin\StaffRole;
use App\Domain\Audit\AuditLog;
use App\Domain\User\UserRepository;
use App\Http\Admin\AdminInput;
use App\Http\Admin\StepUp;
use App\Http\FormFlash;
use App\Http\WorkspaceRequest;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;

/**
 * Admin: staff roles. Only the owner opens this; a person gets a role by the email of an existing, verified account, which needs a fresh code.
 */
final class StaffController
{
    public function __construct(
        private readonly View $view,
        private readonly StaffAccess $staff,
        private readonly UserRepository $users,
        private readonly AuditLog $audit,
        private readonly FormFlash $flash,
        private readonly StepUp $stepUp,
    ) {
    }

    public function index(): Response
    {
        return $this->view->response('admin/staff.twig', [
            'members' => $this->staff->members(),
            'roles' => array_values(array_filter(StaffRole::cases(), static fn (StaffRole $r): bool => $r !== StaffRole::Superadmin)),
        ]);
    }

    public function assign(Request $request): Response
    {
        $actor = WorkspaceRequest::user($request);
        if (($denied = $this->stepUp->guard($request, $actor, '/admin/staff')) !== null) {
            return $denied;
        }
        $email = AdminInput::text($request, 'email', 254);
        $role = StaffRole::tryFrom(AdminInput::text($request, 'role', 16));
        $person = $email === '' ? null : $this->users->findByEmail($email);
        if ($role === null || $role === StaffRole::Superadmin || $person === null || !$person->isVerified() || $person->isBlocked()) {
            $this->flash->toast('Не получилось: нужен существующий аккаунт с подтверждённой почтой и роль из списка.', 'error');

            return Response::redirect('/admin/staff');
        }
        if ($person->isSuperadmin) {
            $this->flash->toast('Это владелец: у него уже есть все права.', 'error');

            return Response::redirect('/admin/staff');
        }
        $before = $this->staff->roleOf($person);
        $this->staff->assign($person->id, $role, $actor->id);
        $this->audit->record('admin.staff_assigned', $actor->id, 'user', (string) $person->id, ['before' => $before === null ? 'none' : $before->value, 'after' => $role->value]);
        $this->flash->toast('Роль «' . $role->label() . '» назначена. Человеку нужно включить двухфакторную защиту, иначе админка не откроется.');

        return Response::redirect('/admin/staff');
    }

    public function remove(Request $request, string $id): Response
    {
        $actor = WorkspaceRequest::user($request);
        if (($denied = $this->stepUp->guard($request, $actor, '/admin/staff')) !== null) {
            return $denied;
        }
        $person = $this->users->find((int) $id);
        $before = $person === null ? null : $this->staff->roleOf($person);
        if ($person === null || $before === null || $before === StaffRole::Superadmin) {
            $this->flash->toast('У этого человека нет роли сотрудника, которую можно снять.', 'error');

            return Response::redirect('/admin/staff');
        }
        $this->staff->remove($person->id);
        $this->audit->record('admin.staff_removed', $actor->id, 'user', (string) $person->id, ['before' => $before->value, 'after' => 'none']);
        $this->flash->toast('Роль снята.');

        return Response::redirect('/admin/staff');
    }
}
