<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workspace;

use App\Domain\Workspace\InvitationRepository;
use App\Domain\Workspace\MemberPolicy;
use App\Domain\Workspace\MemberRepository;
use App\Domain\Workspace\Role;
use App\Domain\Workspace\TeamResult;
use App\Domain\Workspace\TeamService;
use App\Http\FormFlash;
use App\Http\WorkspaceRequest;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\Validation\Validator;
use App\Kernel\View\View;

/**
 * Team page: members, open invitations, inviting, changing roles, removing, leaving and handing the workspace over.
 */
final class TeamController
{
    public function __construct(
        private readonly View $view,
        private readonly Validator $validator,
        private readonly MemberRepository $members,
        private readonly InvitationRepository $invitations,
        private readonly MemberPolicy $policy,
        private readonly TeamService $team,
        private readonly FormFlash $flash,
    ) {
    }

    public function show(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $roles = [];
        foreach ($this->policy->assignableBy($context->role) as $role) {
            $roles[$role->value] = $role->label();
        }

        return $this->view->response('workspace/team.twig', [
            'workspace' => $context,
            'user' => WorkspaceRequest::user($request),
            'members' => $this->members->all($context),
            'invitations' => $this->invitations->open($context),
            'invite_roles' => $roles,
            'role_labels' => array_combine(array_map(static fn (Role $r): string => $r->value, Role::cases()), array_map(static fn (Role $r): string => $r->label(), Role::cases())),
            'role_descriptions' => array_combine(array_map(static fn (Role $r): string => $r->value, Role::cases()), array_map(static fn (Role $r): string => $r->description(), Role::cases())),
            'policy' => $this->policy,
        ]);
    }

    public function invite(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $input = ['email' => mb_strtolower(WorkspaceRequest::text($request->input('email'))), 'role' => WorkspaceRequest::text($request->input('role'))];
        $errors = $this->validator->make($input, [
            'email' => 'required|string|email|max:254',
            'role' => 'required|string|in:' . implode(',', array_map(static fn (Role $r): string => $r->value, Role::assignable())),
        ], ['email' => 'Почта', 'role' => 'Роль'])->errors();
        $role = Role::tryFrom($input['role']);
        if ($errors === [] && $role !== null) {
            $result = $this->team->invite($context, WorkspaceRequest::user($request), $input['email'], $role);
            $message = match ($result) {
                TeamResult::Done => null,
                TeamResult::AlreadyMember => 'Этот человек уже в команде.',
                TeamResult::Throttled => 'Приглашений слишком много. Подождите час и повторите.',
                default => 'У вас нет прав выдавать эту роль.',
            };
            if ($message !== null) {
                $errors['email'] = [$message];
            }
        }
        if ($errors !== []) {
            $this->flash->invalid($input, $errors);

            return $this->back($context->workspacePublicId, '#invite');
        }
        $this->flash->toast('Приглашение отправлено на ' . $input['email'] . '. Ссылка действует ' . TeamService::INVITE_TTL_DAYS . ' дней.');

        return $this->back($context->workspacePublicId);
    }

    public function revokeInvitation(Request $request, string $invitationId): Response
    {
        $context = WorkspaceRequest::context($request);
        $result = $this->team->revokeInvitation($context, $invitationId);
        $this->report($result, 'Приглашение отозвано. Ссылка из письма больше не работает.');

        return $this->back($context->workspacePublicId);
    }

    public function changeRole(Request $request, string $memberId): Response
    {
        $context = WorkspaceRequest::context($request);
        $role = Role::tryFromString($request->input('role'));
        if ($role === null) {
            $this->flash->toast('Выберите роль из списка.', 'error');

            return $this->back($context->workspacePublicId);
        }
        $this->report($this->team->changeRole($context, $memberId, $role), 'Роль изменена: теперь это «' . $role->label() . '».');

        return $this->back($context->workspacePublicId);
    }

    public function remove(Request $request, string $memberId): Response
    {
        $context = WorkspaceRequest::context($request);
        $this->report($this->team->remove($context, $memberId), 'Участник исключён из пространства.');

        return $this->back($context->workspacePublicId);
    }

    public function leave(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $result = $this->team->leave($context);
        if ($result === TeamResult::Done) {
            $this->flash->toast('Вы вышли из пространства «' . $context->workspaceName . '».');

            return Response::redirect('/app');
        }
        $this->report($result, '');

        return $this->back($context->workspacePublicId);
    }

    public function transfer(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $memberId = WorkspaceRequest::text($request->input('member'));
        if (WorkspaceRequest::text($request->input('confirm_name')) !== $context->workspaceName) {
            $this->flash->toast('Название введено неверно. Владение не передано.', 'error');

            return $this->back($context->workspacePublicId);
        }
        $result = $this->team->transferOwnership($context, $memberId);
        $this->report($result, 'Владение передано. Теперь вы администратор пространства.');

        return $result === TeamResult::Done ? Response::redirect('/w/' . $context->workspacePublicId) : $this->back($context->workspacePublicId);
    }

    private function report(TeamResult $result, string $success): void
    {
        match ($result) {
            TeamResult::Done => $this->flash->toast($success),
            TeamResult::NotFound => $this->flash->toast('Не нашли такого участника или приглашения. Возможно, они уже изменились. Обновите страницу.', 'error'),
            TeamResult::OwnerMustTransfer => $this->flash->toast('Владелец не может выйти. Сначала передайте пространство другому участнику.', 'error'),
            TeamResult::Invalid => $this->flash->toast('Передать владение можно только участнику с рабочей ролью, не клиенту и не себе.', 'error'),
            default => $this->flash->toast('У вас нет прав на это действие.', 'error'),
        };
    }

    private function back(string $workspaceId, string $anchor = ''): Response
    {
        return Response::redirect('/w/' . $workspaceId . '/team' . $anchor);
    }
}
