<?php

declare(strict_types=1);

namespace App\Http\Controllers\Automation;

use App\Domain\Automation\AutomationException;
use App\Domain\Automation\Import\ImportBatch;
use App\Domain\Automation\Import\ImportException;
use App\Domain\Automation\Import\ImportRepository;
use App\Domain\Automation\Import\ImportRow;
use App\Domain\Automation\Import\ImportService;
use App\Domain\Workspace\Permissions;
use App\Domain\Workspace\WorkspaceContext;
use App\Http\FormFlash;
use App\Http\WorkspaceRequest;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;

/**
 * Bulk import from a file: the upload page with the template, the check (every row with its errors), the confirmation, the progress of
 * the import and the report of rows that did not make it.
 */
final class ImportController
{
    public function __construct(
        private readonly View $view,
        private readonly FormFlash $flash,
        private readonly AutomationHttp $http,
        private readonly ImportService $service,
        private readonly ImportRepository $repository,
        private readonly Permissions $permissions,
    ) {
    }

    public function index(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $recent = array_map(static fn (ImportBatch $b): array => [
            'id' => $b->publicId, 'filename' => $b->filename, 'status' => $b->status, 'rows' => count($b->rows), 'ok' => $b->okCount(), 'created' => $b->createdCount,
            'mode' => $b->mode === ImportBatch::MODE_SCHEDULE ? 'по расписанию' : 'в черновики',
        ], $this->repository->recent($context));

        return $this->view->response('workspace/automation/import.twig', [
            'workspace' => $context,
            'recent' => $recent,
            'can_schedule' => $this->permissions->allows($context->role, 'posts.publish'),
            'base' => AutomationHttp::base($context),
        ]);
    }

    public function upload(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $back = AutomationHttp::base($context) . '/import';
        $file = $request->files['file'] ?? null;
        $file = is_array($file) ? ($file[0] ?? null) : $file;
        if ($file === null || !$file->isValid()) {
            $this->flash->toast('Файл не выбран или слишком большой. Выберите таблицу CSV или XLSX до 2 МБ.', 'error');

            return Response::redirect($back);
        }
        $mode = AutomationHttp::text($request, 'mode', 12) === ImportBatch::MODE_SCHEDULE ? ImportBatch::MODE_SCHEDULE : ImportBatch::MODE_DRAFT;
        try {
            $batch = $this->service->preview($context, $file->path, $file->clientName, $mode);
        } catch (ImportException $e) {
            $this->flash->toast($e->getMessage(), 'error');

            return Response::redirect($back);
        } catch (AutomationException $e) {
            return $this->http->refuse($context, $e, $back);
        }

        return Response::redirect($back . '/' . $batch->publicId);
    }

    public function show(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $batch = $this->batch($context, $request);
        $failedLines = array_column($batch->failed, 'message', 'line');

        return $this->view->response('workspace/automation/import_show.twig', [
            'workspace' => $context,
            'batch' => $batch,
            'rows' => array_map(static fn (ImportRow $r): array => [
                'line' => $r->line,
                'when' => $r->date === null ? null : substr($r->date, 8, 2) . '.' . substr($r->date, 5, 2) . '.' . substr($r->date, 0, 4) . ' ' . $r->time,
                'channels' => implode(', ', $r->channelNames),
                'text' => mb_substr($r->text, 0, 120),
                'media' => count($r->media),
                'errors' => $r->errors,
                'failed' => $failedLines[$r->line] ?? null,
            ], array_slice($batch->rows, 0, 200)),
            'more' => max(0, count($batch->rows) - 200),
            'ok' => $batch->okCount(),
            'bad' => $batch->errorCount(),
            'timezone' => $context->timezone,
            'schedule' => $batch->mode === ImportBatch::MODE_SCHEDULE,
            'base' => AutomationHttp::base($context),
        ]);
    }

    public function confirm(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $batch = $this->batch($context, $request);
        $back = AutomationHttp::base($context) . '/import/' . $batch->publicId;
        try {
            $this->service->confirm($context, $batch);
        } catch (AutomationException $e) {
            return $this->http->refuse($context, $e, $back);
        }
        $this->flash->toast('Импорт запущен. Страница покажет, сколько постов создано.');

        return Response::redirect($back);
    }

    public function errors(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);

        return $this->csv($this->service->errorReport($this->batch($context, $request)), 'import-errors.csv');
    }

    public function template(Request $request): Response
    {
        return $this->csv(ImportService::template(), 'posts-template.csv');
    }

    private function csv(string $body, string $filename): Response
    {
        return Response::text($body)->withHeader('Content-Type', 'text/csv; charset=utf-8')->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')->withHeader('X-Content-Type-Options', 'nosniff');
    }

    private function batch(WorkspaceContext $context, Request $request): ImportBatch
    {
        return $this->repository->find($context, AutomationHttp::id($request, 'batchId')) ?? throw new HttpException(404, 'Not found');
    }
}
