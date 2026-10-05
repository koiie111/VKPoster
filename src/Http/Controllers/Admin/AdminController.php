<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\AdminStats;
use App\Domain\Audit\AuditLog;
use App\Domain\Auth\LoginService;
use App\Domain\Legal\LegalDocuments;
use App\Domain\Status\PlatformStatus;
use App\Http\FormFlash;
use App\Http\Middleware\RequireAdminUnlock;
use App\Http\WorkspaceRequest;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;
use App\Support\Clock;

/**
 * Admin entrance: the second-factor unlock page and the overview with the numbers that matter on day one.
 */
final class AdminController
{
    public function __construct(
        private readonly View $view,
        private readonly AdminStats $stats,
        private readonly LegalDocuments $legal,
        private readonly PlatformStatus $status,
        private readonly LoginService $login,
        private readonly AuditLog $audit,
        private readonly FormFlash $flash,
        private readonly Clock $clock,
    ) {
    }

    public function overview(): Response
    {
        $unfilled = [];
        foreach ($this->legal->all() as $document) {
            if ($document->isDraft()) {
                $unfilled[] = ['title' => $document->title, 'count' => $document->placeholders(), 'slug' => $document->slug];
            }
        }

        return $this->view->response('admin/overview.twig', [
            'accounts' => $this->stats->accounts(),
            'publications' => $this->stats->publications(),
            'queues' => $this->stats->queues(),
            'channels' => $this->stats->channels(),
            'problems' => $this->status->problems(),
            'unfilled_documents' => $unfilled,
        ]);
    }

    public function unlockShow(Request $request): Response
    {
        if (RequireAdminUnlock::isUnlocked($this->flash->session(), $this->clock)) {
            return Response::redirect('/admin');
        }

        return $this->view->response('admin/unlock.twig');
    }

    public function unlock(Request $request): Response
    {
        $user = WorkspaceRequest::user($request);
        $code = WorkspaceRequest::text($request->input('code'));
        $outcome = $code === '' ? 'invalid' : $this->login->checkSecondFactor($user, $code, $request->ip(), $request->header('user-agent') ?? '');
        $session = $this->flash->session();
        if ($outcome !== 'ok') {
            $this->audit->record('admin.unlock_failed', $user->id, 'user', (string) $user->id);
            $this->flash->invalid([], ['code' => $outcome === 'throttled'
                ? 'Слишком много попыток. Подождите 10 минут и повторите.'
                : 'Код не подошёл. Проверьте код в приложении или введите резервный код.']);

            return Response::redirect('/admin/unlock');
        }
        $session->set(RequireAdminUnlock::SESSION_KEY, $this->clock->now()->getTimestamp());
        $session->set(RequireAdminUnlock::LAST_SEEN_KEY, $this->clock->now()->getTimestamp());
        $this->audit->record('admin.unlocked', $user->id, 'user', (string) $user->id);
        $intended = $session->pull('admin.intended');

        return Response::redirect(is_string($intended) && str_starts_with($intended, '/admin') && Response::isRelativeUrl($intended) ? $intended : '/admin');
    }
}
