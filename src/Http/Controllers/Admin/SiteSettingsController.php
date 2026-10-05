<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\DailyReport;
use App\Domain\Auth\RegistrationGate;
use App\Domain\Audit\AuditLog;
use App\Domain\Settings\SiteSettings;
use App\Http\Admin\AdminInput;
use App\Http\Admin\StepUp;
use App\Http\FormFlash;
use App\Http\WorkspaceRequest;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;
use App\Support\Clock;

/**
 * Admin: switches of the site that change without a deploy: maintenance mode, who may register (and the invitation codes), contact details
 * and requisites, global limits. Switching maintenance on or closing registration asks for a fresh code, because it affects every customer.
 */
final class SiteSettingsController
{
    /** Time zones offered for the report hour (the owner is in Russia; UTC for anybody else). */
    private const ZONES = ['Europe/Kaliningrad' => 'Калининград', 'Europe/Moscow' => 'Москва', 'Europe/Samara' => 'Самара', 'Asia/Yekaterinburg' => 'Екатеринбург', 'Asia/Omsk' => 'Омск', 'Asia/Krasnoyarsk' => 'Красноярск', 'Asia/Irkutsk' => 'Иркутск', 'Asia/Yakutsk' => 'Якутск', 'Asia/Vladivostok' => 'Владивосток', 'Asia/Magadan' => 'Магадан', 'Asia/Kamchatka' => 'Камчатка', 'UTC' => 'UTC'];

    public function __construct(
        private readonly View $view,
        private readonly SiteSettings $site,
        private readonly RegistrationGate $gate,
        private readonly AuditLog $audit,
        private readonly FormFlash $flash,
        private readonly StepUp $stepUp,
        private readonly Clock $clock,
        private readonly DailyReport $report,
    ) {
    }

    public function show(): Response
    {
        return $this->view->response('admin/settings.twig', [
            'maintenance' => $this->site->maintenanceOn(),
            'maintenance_message' => $this->site->maintenanceMessage(),
            'registration' => $this->site->registrationMode(),
            'modes' => SiteSettings::REGISTRATION_MODES,
            'support_email' => $this->site->supportEmail(),
            'support_telegram' => $this->site->supportTelegram(),
            'requisites' => $this->site->requisites(),
            'max_workspaces' => $this->site->maxWorkspacesPerUser(),
            'codes' => $this->gate->codes(),
            'report' => $this->report->config(),
            'zones' => self::ZONES,
            'new_code' => $this->flash->session()->getFlash('admin.new_invite_code'),
        ]);
    }

    public function save(Request $request): Response
    {
        $staff = WorkspaceRequest::user($request);
        $maintenance = $request->input('maintenance') === '1';
        $registration = AdminInput::text($request, 'registration', 10);
        $risky = $maintenance !== $this->site->maintenanceOn() || ($registration !== $this->site->registrationMode() && $registration !== 'open');
        if ($risky && ($denied = $this->stepUp->guard($request, $staff, '/admin/settings')) !== null) {
            return $denied;
        }
        $before = ['maintenance' => $this->site->maintenanceOn(), 'registration' => $this->site->registrationMode(), 'max_workspaces' => $this->site->maxWorkspacesPerUser()];
        $errors = $this->site->save([
            'maintenance' => $maintenance ? '1' : '',
            'maintenance_message' => AdminInput::text($request, 'maintenance_message', 600),
            'registration' => $registration,
            'support_email' => AdminInput::text($request, 'support_email', 254),
            'support_telegram' => AdminInput::text($request, 'support_telegram', 40),
            'requisites' => AdminInput::text($request, 'requisites', 3100),
            'max_workspaces' => AdminInput::text($request, 'max_workspaces', 6),
        ], $staff->id);
        if ($errors !== []) {
            $this->flash->toast(implode(' ', $errors), 'error');

            return Response::redirect('/admin/settings');
        }
        $after = ['maintenance' => $this->site->maintenanceOn(), 'registration' => $this->site->registrationMode(), 'max_workspaces' => $this->site->maxWorkspacesPerUser()];
        $this->audit->record('admin.settings_changed', $staff->id, 'setting', 'site', ['before' => json_encode($before), 'after' => json_encode($after)]);
        $this->flash->toast($maintenance ? 'Настройки сохранены. Включён режим обслуживания: обычные пользователи видят страницу о работах.' : 'Настройки сохранены.');

        return Response::redirect('/admin/settings');
    }

    public function saveReport(Request $request): Response
    {
        $staff = WorkspaceRequest::user($request);
        $errors = $this->report->save([
            'enabled' => $request->input('enabled') === '1' ? '1' : '',
            'hour' => AdminInput::text($request, 'hour', 3),
            'timezone' => AdminInput::text($request, 'timezone', 40),
            'email' => $request->input('email') === '1' ? '1' : '',
            'telegram' => $request->input('telegram') === '1' ? '1' : '',
        ], $staff->id);
        if ($errors !== []) {
            $this->flash->toast(implode(' ', $errors), 'error');

            return Response::redirect('/admin/settings');
        }
        $this->audit->record('admin.settings_changed', $staff->id, 'setting', 'report', ['after' => json_encode($this->report->config())]);
        $this->flash->toast('Настройки отчёта сохранены.');

        return Response::redirect('/admin/settings');
    }

    public function testReport(Request $request): Response
    {
        $sent = $this->report->send([WorkspaceRequest::user($request)]);
        $this->flash->toast($sent === 0 ? 'Отправить некуда: включите почту или подключите Telegram в уведомлениях.' : 'Отчёт отправлен вам.', $sent === 0 ? 'warning' : 'success');

        return Response::redirect('/admin/settings');
    }

    public function createCode(Request $request): Response
    {
        $staff = WorkspaceRequest::user($request);
        $uses = AdminInput::text($request, 'max_uses', 5);
        $days = AdminInput::text($request, 'days', 4);
        $expires = ctype_digit($days) && (int) $days > 0 ? $this->clock->now()->modify('+' . (int) $days . ' days') : null;
        $code = $this->gate->create(AdminInput::text($request, 'note', 150), ctype_digit($uses) ? (int) $uses : 1, $expires, $staff->id);
        $this->audit->record('admin.settings_changed', $staff->id, 'setting', 'invite_code', ['after' => 'created']);
        // The plain code is shown once; only its hash is stored.
        $this->flash->session()->flash('admin.new_invite_code', $code);

        return Response::redirect('/admin/settings');
    }

    public function revokeCode(Request $request, string $id): Response
    {
        $this->gate->revoke((int) $id);
        $this->audit->record('admin.settings_changed', WorkspaceRequest::user($request)->id, 'setting', 'invite_code', ['after' => 'revoked ' . (int) $id]);
        $this->flash->toast('Код отозван.');

        return Response::redirect('/admin/settings');
    }
}
