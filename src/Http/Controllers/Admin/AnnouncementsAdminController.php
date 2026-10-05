<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\UserDirectory;
use App\Domain\Audit\AuditLog;
use App\Domain\Content\Announcements;
use App\Http\Admin\AdminInput;
use App\Http\FormFlash;
use App\Http\WorkspaceRequest;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Admin: notices shown inside the app (to everybody, to some plans, to workspaces with channels in some networks, for a period).
 */
final class AnnouncementsAdminController
{
    public function __construct(
        private readonly View $view,
        private readonly Announcements $announcements,
        private readonly UserDirectory $directory,
        private readonly AuditLog $audit,
        private readonly FormFlash $flash,
    ) {
    }

    public function index(): Response
    {
        return $this->view->response('admin/announcements.twig', ['notices' => $this->announcements->all(), 'form' => null, 'levels' => Announcements::LEVELS, 'plans' => $this->directory->plans()]);
    }

    public function edit(string $id): Response
    {
        $item = $this->announcements->find((int) $id) ?? throw new HttpException(404, 'Not found');

        return $this->view->response('admin/announcements.twig', ['notices' => $this->announcements->all(), 'form' => $item, 'levels' => Announcements::LEVELS, 'plans' => $this->directory->plans()]);
    }

    public function save(Request $request, ?string $id = null): Response
    {
        $staff = WorkspaceRequest::user($request);
        $tz = $staff->timezone;
        $plans = $request->input('plans');
        $platforms = $request->input('platforms');
        $start = $this->moment($request, 'starts', $tz);
        $end = $this->moment($request, 'ends', $tz);
        $errors = $this->announcements->save(
            $id === null ? null : (int) $id,
            AdminInput::text($request, 'title', 150),
            AdminInput::text($request, 'body', 1000),
            AdminInput::text($request, 'level', 10),
            is_array($plans) ? array_values(array_filter($plans, 'is_string')) : [],
            is_array($platforms) ? array_values(array_filter($platforms, 'is_string')) : [],
            $start,
            $end,
            $staff->id,
        );
        if ($errors !== []) {
            $this->flash->toast(implode(' ', $errors), 'error');

            return Response::redirect($id === null ? '/admin/announcements' : '/admin/announcements/' . (int) $id);
        }
        $this->audit->record('admin.content_changed', $staff->id, 'announcement', $id ?? 'new', ['title' => AdminInput::text($request, 'title', 150)]);
        $this->flash->toast('Объявление сохранено.');

        return Response::redirect('/admin/announcements');
    }

    public function toggle(Request $request, string $id): Response
    {
        $item = $this->announcements->find((int) $id) ?? throw new HttpException(404, 'Not found');
        $this->announcements->setActive((int) $id, (int) $item['is_active'] !== 1);
        $this->audit->record('admin.content_changed', WorkspaceRequest::user($request)->id, 'announcement', $id, ['active' => (int) $item['is_active'] !== 1 ? 'on' : 'off']);

        return Response::redirect('/admin/announcements');
    }

    public function delete(Request $request, string $id): Response
    {
        $this->announcements->delete((int) $id);
        $this->audit->record('admin.content_changed', WorkspaceRequest::user($request)->id, 'announcement', $id, ['deleted' => 'yes']);
        $this->flash->toast('Объявление удалено.');

        return Response::redirect('/admin/announcements');
    }

    /**
     * A `datetime-local` value of the form, read in the staff member's time zone.
     */
    private function moment(Request $request, string $key, string $tz): ?DateTimeImmutable
    {
        $value = $request->input($key);
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value) !== 1) {
            return null;
        }
        try {
            $moment = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value, new DateTimeZone($tz));
        } catch (\Exception) {
            return null;
        }

        return $moment === false ? null : $moment->setTimezone(new DateTimeZone('UTC'));
    }
}
