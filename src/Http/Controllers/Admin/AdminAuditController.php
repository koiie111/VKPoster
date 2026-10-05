<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\AdminAuditReader;
use App\Domain\Audit\AuditLog;
use App\Http\Admin\AdminInput;
use App\Http\WorkspaceRequest;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;
use App\Support\Csv;
use DateTimeImmutable;

/**
 * Admin: the journal of what staff did, with filters and a CSV export (the export is itself written to the journal).
 */
final class AdminAuditController
{
    public function __construct(
        private readonly View $view,
        private readonly AdminAuditReader $reader,
        private readonly AuditLog $audit,
    ) {
    }

    public function index(Request $request): Response
    {
        $tz = WorkspaceRequest::user($request)->timezone;
        $filters = $this->filters($request, $tz);
        $params = $this->params($request);

        return $this->view->response('admin/audit.twig', [
            'filters' => $params,
            'actions' => AdminAuditReader::actions(),
            'result' => $this->reader->page($filters, AdminInput::page($request)),
            'base' => '/admin/audit' . AdminInput::query($params),
            'export_href' => '/admin/audit/export' . AdminInput::query($params),
        ]);
    }

    public function export(Request $request): Response
    {
        $user = WorkspaceRequest::user($request);
        $rows = (function () use ($request, $user): \Generator {
            foreach ($this->reader->export($this->filters($request, $user->timezone)) as $row) {
                yield [
                    $row['id'], $row['created_at'], $row['action'], $row['label'], $row['actor_id'], $row['actor_email'],
                    $row['subject_type'], $row['subject_id'], $row['ip'], $row['details'],
                ];
            }
        })();
        $csv = Csv::build(['id', 'время (UTC)', 'действие', 'название', 'кто (id)', 'кто (почта)', 'объект', 'номер объекта', 'IP', 'подробности'], $rows);
        $this->audit->record('admin.audit_exported', $user->id, null, null, ['bytes' => strlen($csv)]);

        return (new Response(200, $csv))
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="audit-' . gmdate('Y-m-d') . '.csv"')
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * @return array{scope: string, action: string, actor: string, subject: string, from: ?DateTimeImmutable, to: ?DateTimeImmutable}
     */
    private function filters(Request $request, string $tz): array
    {
        return [
            'scope' => AdminInput::choice($request, 'scope', ['admin', 'all'], 'admin'),
            'action' => AdminInput::choice($request, 'action', array_keys(AdminAuditReader::actions())),
            'actor' => AdminInput::text($request, 'actor', 100),
            'subject' => AdminInput::text($request, 'subject', 64),
            'from' => AdminInput::date($request, 'from', $tz),
            'to' => AdminInput::date($request, 'to', $tz, true),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function params(Request $request): array
    {
        $params = [];
        foreach (['scope', 'action', 'actor', 'subject', 'from', 'to'] as $key) {
            $value = AdminInput::text($request, $key, 100);
            if ($value !== '') {
                $params[$key] = $value;
            }
        }

        return $params;
    }
}
