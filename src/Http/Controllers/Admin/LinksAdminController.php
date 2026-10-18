<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditLog;
use App\Domain\Link\ShortLinks;
use App\Domain\Link\UrlGuard;
use App\Domain\Settings\Settings;
use App\Http\FormFlash;
use App\Http\WorkspaceRequest;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;

/**
 * Admin: short links of all workspaces (search by code or address, switch one off or on) and the list of forbidden domains.
 * Adding a domain also switches off every existing short link that leads there; posts that contain such an address are refused
 * when they are checked or published.
 */
final class LinksAdminController
{
    public function __construct(
        private readonly View $view,
        private readonly ShortLinks $links,
        private readonly UrlGuard $guard,
        private readonly Settings $settings,
        private readonly AuditLog $audit,
        private readonly FormFlash $flash,
    ) {
    }

    public function index(Request $request): Response
    {
        $term = mb_substr(WorkspaceRequest::text($request->input('q')), 0, 200);
        $rows = [];
        foreach ($this->links->search($term, 100) as $link) {
            $rows[] = [
                'id' => (string) $link['public_id'],
                'url' => $this->links->urlFor((string) $link['code']),
                'target' => (string) $link['target_url'],
                'workspace' => (string) $link['workspace_name'],
                'platform' => (string) $link['platform'],
                'clicks' => (int) $link['clicks'],
                'human' => (int) $link['human_clicks'],
                'created' => $link['created_at'],
                'disabled' => $link['disabled_at'] !== null,
                'reason' => $link['disabled_reason'],
            ];
        }

        return $this->view->response('admin/links/index.twig', ['links' => $rows, 'q' => $term, 'blocked' => implode("\n", $this->guard->blocked())]);
    }

    public function toggle(Request $request, string $id): Response
    {
        $found = $this->links->findAny($id) ?? throw new HttpException(404, 'Not found');
        $off = $found['disabled_at'] === null;
        $this->links->setDisabled((int) $found['id'], $off, $off ? self::reason($request) : '');
        $this->audit->record($off ? 'admin.link_disabled' : 'admin.link_enabled', WorkspaceRequest::user($request)->id, 'short_link', (string) $found['id'], [], (int) $found['workspace_id']);
        $this->flash->toast($off ? 'Ссылка отключена.' : 'Ссылка включена.');

        return Response::redirect('/admin/links');
    }

    public function saveBlocklist(Request $request): Response
    {
        $domains = UrlGuard::parseList((string) $request->input('domains'));
        $this->settings->set(UrlGuard::SETTING, $domains, WorkspaceRequest::user($request)->id);
        $switchedOff = $this->links->disableBlocked();
        $this->audit->record('admin.link_blocklist_updated', WorkspaceRequest::user($request)->id, 'settings', UrlGuard::SETTING, ['domains' => (string) count($domains), 'switched_off' => (string) $switchedOff]);
        $this->flash->toast('Список сохранён: ' . count($domains) . ' доменов. Отключено ссылок: ' . $switchedOff . '.');

        return Response::redirect('/admin/links#blocklist');
    }

    private static function reason(Request $request): string
    {
        $reason = WorkspaceRequest::text($request->input('reason'));

        return $reason === '' ? 'Отключено поддержкой' : $reason;
    }

}
