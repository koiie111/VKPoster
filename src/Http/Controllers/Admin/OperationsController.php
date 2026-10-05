<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\AdminStats;
use App\Domain\Admin\FailedJobs;
use App\Domain\Audit\AuditLog;
use App\Domain\Settings\Settings;
use App\Domain\Status\PlatformStatus;
use App\Http\FormFlash;
use App\Http\WorkspaceRequest;
use App\Integrations\Social\Contracts\Platform;
use App\Integrations\Social\PlatformRegistry;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;

/**
 * Admin: the queue and failed jobs (retry, discard), channel health by network, the platform switches and the status notices, the promo codes stub.
 */
final class OperationsController
{
    public function __construct(
        private readonly View $view,
        private readonly AdminStats $stats,
        private readonly FailedJobs $failed,
        private readonly PlatformRegistry $registry,
        private readonly PlatformStatus $status,
        private readonly Settings $settings,
        private readonly AuditLog $audit,
        private readonly FormFlash $flash,
    ) {
    }

    public function queues(Request $request): Response
    {
        $page = $request->input('page');

        return $this->view->response('admin/queues.twig', [
            'queues' => $this->stats->queues(),
            'failed' => $this->failed->page(is_string($page) && ctype_digit($page) ? (int) $page : 1),
        ]);
    }

    public function retry(Request $request, string $id): Response
    {
        $ok = $this->failed->retry((int) $id);
        if ($ok) {
            $this->audit->record('admin.job_retried', WorkspaceRequest::user($request)->id, 'failed_job', $id);
        }
        $this->flash->toast($ok ? 'Задача снова в очереди.' : 'Такой задачи уже нет.', $ok ? 'success' : 'warning');

        return Response::redirect('/admin/queues');
    }

    public function discard(Request $request, string $id): Response
    {
        $ok = $this->failed->discard((int) $id);
        if ($ok) {
            $this->audit->record('admin.job_discarded', WorkspaceRequest::user($request)->id, 'failed_job', $id);
        }
        $this->flash->toast($ok ? 'Задача удалена.' : 'Такой задачи уже нет.', $ok ? 'success' : 'warning');

        return Response::redirect('/admin/queues');
    }

    public function channels(): Response
    {
        $platforms = [];
        $counts = $this->stats->channels();
        foreach ($this->registry->configured() as $platform) {
            $platforms[] = ['platform' => $platform, 'counts' => $counts[$platform->value] ?? []];
        }

        return $this->view->response('admin/channels.twig', ['platforms' => $platforms, 'broken' => $this->stats->brokenChannels()]);
    }

    public function platforms(): Response
    {
        $off = array_values(array_filter((array) $this->settings->get('platforms.off', []), 'is_string'));
        $notices = (array) $this->settings->get('status.notices', []);
        $rows = [];
        foreach ($this->registry->configured() as $platform) {
            if ($platform === Platform::Fake) {
                continue;
            }
            $notice = $notices[$platform->value] ?? '';
            $rows[] = ['platform' => $platform, 'on' => !in_array($platform->value, $off, true), 'notice' => is_string($notice) ? $notice : ''];
        }

        return $this->view->response('admin/platforms.twig', ['rows' => $rows]);
    }

    public function savePlatforms(Request $request): Response
    {
        $staff = WorkspaceRequest::user($request);
        $off = [];
        $notices = [];
        foreach ($this->registry->configured() as $platform) {
            if ($platform === Platform::Fake) {
                continue;
            }
            if ($request->input('on_' . $platform->value) !== '1') {
                $off[] = $platform->value;
            }
            $notice = mb_substr(WorkspaceRequest::text($request->input('notice_' . $platform->value)), 0, 300);
            if ($notice !== '') {
                $notices[$platform->value] = $notice;
            }
        }
        $this->settings->set('platforms.off', $off, $staff->id);
        $this->settings->set('status.notices', $notices, $staff->id);
        $this->status->refresh();
        $this->audit->record('admin.platforms_changed', $staff->id, 'settings', 'platforms', ['off' => implode(',', $off), 'notices' => count($notices)]);
        $this->flash->toast('Сохранено. Изменения действуют сразу.');

        return Response::redirect('/admin/platforms');
    }

    public function promo(): Response
    {
        return $this->view->response('admin/promo.twig');
    }
}
