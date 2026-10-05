<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\StaffAccess;
use App\Domain\Admin\UserActions;
use App\Domain\Admin\UserDirectory;
use App\Domain\Audit\AuditLog;
use App\Domain\Auth\SessionRegistry;
use App\Domain\Billing\Ledger;
use App\Domain\User\User;
use App\Domain\User\UserRepository;
use App\Http\Admin\AdminInput;
use App\Http\Admin\StepUp;
use App\Http\Auth\Impersonation;
use App\Http\FormFlash;
use App\Http\WorkspaceRequest;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;
use App\Support\Csv;
use App\Support\Money;
use DateTimeImmutable;

/**
 * Admin: find a person (search, filters, sorting, CSV export), see everything about them on one card, act on the account (block, sign out,
 * reset the second factor, confirm the email, give days/credits/money, leave a note) and sign in as them for support.
 */
final class UsersController
{
    private const SORTS = ['created', 'name', 'email', 'workspaces', 'activity'];

    public function __construct(
        private readonly View $view,
        private readonly UserDirectory $directory,
        private readonly UserActions $actions,
        private readonly UserRepository $users,
        private readonly SessionRegistry $sessions,
        private readonly Impersonation $impersonation,
        private readonly AuditLog $audit,
        private readonly FormFlash $flash,
        private readonly StepUp $stepUp,
        private readonly StaffAccess $staff,
        private readonly Ledger $ledger,
    ) {
    }

    public function index(Request $request): Response
    {
        $tz = WorkspaceRequest::user($request)->timezone;
        $filters = $this->filters($request, $tz);
        $params = $this->params($request);
        $sortKey = AdminInput::choice($request, 'sort', self::SORTS, 'created');
        $dir = AdminInput::choice($request, 'dir', ['asc', 'desc'], 'desc');
        $base = '/admin/users' . AdminInput::query($params);
        $sortLink = static fn (string $key): string => '/admin/users' . AdminInput::query([...$params, 'sort' => $key, 'dir' => $sortKey === $key && $dir === 'desc' ? 'asc' : 'desc']);

        return $this->view->response('admin/users/index.twig', [
            'filters' => $params,
            'result' => $this->directory->page($filters, AdminInput::page($request)),
            'base' => $base,
            'plan_options' => ['' => 'Любой тариф'] + $this->directory->plans(),
            'source_options' => ['' => 'Любой источник'] + array_combine($this->directory->sources(), $this->directory->sources()),
            'sort' => ['key' => $sortKey, 'dir' => $dir, 'links' => array_combine(self::SORTS, array_map($sortLink, self::SORTS))],
            'export_href' => '/admin/users/export' . AdminInput::query($params),
        ]);
    }

    public function export(Request $request): Response
    {
        $staff = WorkspaceRequest::user($request);
        $rows = (function () use ($request, $staff): \Generator {
            foreach ($this->directory->export($this->filters($request, $staff->timezone)) as $row) {
                yield [
                    $row['id'], $row['email'], $row['name'], $row['status'], $row['plan'], $row['source'], $row['workspaces'],
                    $row['created_at'], $row['email_verified_at'] !== null, $row['last_active'] instanceof DateTimeImmutable ? $row['last_active']->format('Y-m-d') : '',
                ];
            }
        })();
        $csv = Csv::build(['id', 'почта', 'имя', 'статус', 'тариф', 'источник', 'пространств', 'зарегистрирован (UTC)', 'почта подтверждена', 'последняя активность'], $rows);
        $this->audit->record('admin.users_exported', $staff->id, null, null, ['filters' => json_encode($this->params($request), JSON_UNESCAPED_UNICODE), 'bytes' => strlen($csv)]);

        return (new Response(200, $csv))
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="users-' . gmdate('Y-m-d') . '.csv"')
            ->withHeader('Cache-Control', 'no-store');
    }

    public function show(Request $request, string $id): Response
    {
        $viewer = WorkspaceRequest::user($request);
        $person = $this->users->find((int) $id) ?? throw new HttpException(404, 'Not found');
        $owned = $this->directory->ownedWorkspaces($person->id);
        foreach ($owned as $i => $workspace) {
            $owned[$i]['wallet'] = $this->ledger->wallet((string) $workspace['public_id']);
        }
        $this->audit->record('admin.user_viewed', $viewer->id, 'user', (string) $person->id);

        return $this->view->response('admin/users/show.twig', [
            'person' => $person,
            'role' => $this->staff->roleOf($person),
            'summary' => $this->directory->summary($person->id),
            'workspaces' => $this->directory->memberships($person->id),
            'owned' => $owned,
            'identities' => $this->directory->identities($person->id),
            'sessions' => $this->directory->sessions($person->id),
            'payments' => $this->staff->can($viewer, 'finance.view') ? $this->directory->payments($person->id) : [],
            'channels' => $this->directory->channels($person->id),
            'publications' => $this->directory->publications($person->id),
            'notes' => $this->directory->notes($person->id),
            'journal' => $this->directory->journal($person->id),
            'attribution' => $this->directory->attribution($person->id),
            'can_grant' => $this->staff->can($viewer, 'grants.manage'),
            'target_is_staff' => $this->staff->isStaff($person),
        ]);
    }

    public function block(Request $request, string $id): Response
    {
        $staff = WorkspaceRequest::user($request);
        $target = $this->target((int) $id);
        if (($denied = $this->stepUp->guard($request, $staff, '/admin/users/' . $target->id)) !== null) {
            return $denied;
        }
        $reason = AdminInput::text($request, 'reason', 500);
        if ($reason === '') {
            $this->flash->toast('Напишите причину блокировки: человек увидит её при попытке войти.', 'error');

            return Response::redirect('/admin/users/' . $target->id);
        }
        if ($target->id === $staff->id || $this->staff->isStaff($target)) {
            $this->flash->toast('Этого человека заблокировать нельзя: он сотрудник.', 'error');

            return Response::redirect('/admin/users/' . $target->id);
        }
        $this->users->setStatus($target->id, User::STATUS_BLOCKED, $reason);
        // A blocked person is signed out everywhere at once.
        $this->sessions->revokeAll($target->id);
        $this->audit->record('admin.user_blocked', $staff->id, 'user', (string) $target->id, ['before' => $target->status, 'after' => User::STATUS_BLOCKED, 'reason' => $reason]);
        $this->flash->toast('Аккаунт заблокирован, все его сессии завершены.');

        return Response::redirect('/admin/users/' . $target->id);
    }

    public function unblock(Request $request, string $id): Response
    {
        $staff = WorkspaceRequest::user($request);
        $target = $this->target((int) $id);
        $this->users->setStatus($target->id, User::STATUS_ACTIVE);
        $this->audit->record('admin.user_unblocked', $staff->id, 'user', (string) $target->id, ['before' => $target->status, 'after' => User::STATUS_ACTIVE]);
        $this->flash->toast('Аккаунт разблокирован.');

        return Response::redirect('/admin/users/' . $target->id);
    }

    public function signOut(Request $request, string $id): Response
    {
        $target = $this->target((int) $id);
        $count = $this->actions->signOutEverywhere($target, WorkspaceRequest::user($request));
        $this->flash->toast($count === 0 ? 'Активных входов у человека не было.' : 'Человек разлогинен на всех устройствах (' . $count . ').');

        return Response::redirect('/admin/users/' . $target->id);
    }

    public function resetTwoFactor(Request $request, string $id): Response
    {
        $staff = WorkspaceRequest::user($request);
        $target = $this->target((int) $id);
        if (($denied = $this->stepUp->guard($request, $staff, '/admin/users/' . $target->id)) !== null) {
            return $denied;
        }
        $error = $this->actions->resetTwoFactor($target, $staff);
        $this->flash->toast($error ?? 'Двухфакторная защита сброшена, человек разлогинен и включит её заново.', $error === null ? 'success' : 'error');

        return Response::redirect('/admin/users/' . $target->id);
    }

    public function verifyEmail(Request $request, string $id): Response
    {
        $target = $this->target((int) $id);
        $done = $this->actions->verifyEmail($target, WorkspaceRequest::user($request));
        $this->flash->toast($done ? 'Почта отмечена как подтверждённая.' : 'Подтверждать нечего: почты нет или она уже подтверждена.', $done ? 'success' : 'error');

        return Response::redirect('/admin/users/' . $target->id);
    }

    public function note(Request $request, string $id): Response
    {
        $target = $this->target((int) $id);
        $done = $this->actions->addNote($target, WorkspaceRequest::user($request), AdminInput::text($request, 'body', 2000));
        $this->flash->toast($done ? 'Заметка сохранена. Человек её не видит.' : 'Напишите текст заметки (до 2000 символов).', $done ? 'success' : 'error');

        return Response::redirect('/admin/users/' . $target->id);
    }

    public function grant(Request $request, string $id): Response
    {
        $staff = WorkspaceRequest::user($request);
        $target = $this->target((int) $id);
        if (($denied = $this->stepUp->guard($request, $staff, '/admin/users/' . $target->id)) !== null) {
            return $denied;
        }
        $unit = AdminInput::text($request, 'unit', 10);
        $raw = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], AdminInput::text($request, 'amount', 20));
        // Money is typed in roubles and kept in kopecks; days and credits are whole numbers.
        $amount = $unit === 'balance' ? (Money::fromDecimal($raw) ?? 0) : (ctype_digit($raw) && strlen($raw) < 10 ? (int) $raw : 0);
        $error = $this->actions->grant($target, AdminInput::text($request, 'workspace', 26), $unit, $amount, AdminInput::text($request, 'reason', 200), $staff);
        $this->flash->toast($error ?? 'Начислено. Запись есть в журнале проводок и в журнале действий.', $error === null ? 'success' : 'error');

        return Response::redirect('/admin/users/' . $target->id);
    }

    public function impersonate(Request $request, string $id): Response
    {
        $staff = WorkspaceRequest::user($request);
        $target = $this->target((int) $id);
        if (($denied = $this->stepUp->guard($request, $staff, '/admin/users/' . $target->id)) !== null) {
            return $denied;
        }
        if ($target->id === $staff->id || $this->staff->isStaff($target) || $target->isBlocked()) {
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

    /**
     * @return array{q: string, plan: string, status: string, from: ?DateTimeImmutable, to: ?DateTimeImmutable, source: string, activity: string, sort: string, dir: string}
     */
    private function filters(Request $request, string $tz): array
    {
        return [
            'q' => AdminInput::text($request, 'q', 100),
            'plan' => AdminInput::text($request, 'plan', 32),
            'status' => AdminInput::choice($request, 'status', ['active', 'blocked', 'staff', 'unverified']),
            'from' => AdminInput::date($request, 'from', $tz),
            'to' => AdminInput::date($request, 'to', $tz, true),
            'source' => AdminInput::text($request, 'source', 60),
            'activity' => AdminInput::choice($request, 'activity', ['active', 'inactive']),
            'sort' => AdminInput::choice($request, 'sort', self::SORTS, 'created'),
            'dir' => AdminInput::choice($request, 'dir', ['asc', 'desc'], 'desc'),
        ];
    }

    /**
     * The filters that are set, for links and for the export's record in the journal.
     *
     * @return array<string, string>
     */
    private function params(Request $request): array
    {
        $params = [];
        foreach (['q', 'plan', 'status', 'from', 'to', 'source', 'activity', 'sort', 'dir'] as $key) {
            $value = AdminInput::text($request, $key, 100);
            if ($value !== '') {
                $params[$key] = $value;
            }
        }

        return $params;
    }
}
