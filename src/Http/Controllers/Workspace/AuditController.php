<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workspace;

use App\Domain\Audit\AuditActions;
use App\Domain\Audit\AuditReader;
use App\Http\WorkspaceRequest;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Workspace journal ("who did what") with filters by kind of action, person and date.
 */
final class AuditController
{
    public function __construct(
        private readonly View $view,
        private readonly AuditReader $reader,
    ) {
    }

    public function show(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $group = WorkspaceRequest::text($request->input('group'));
        $group = isset(AuditActions::groups()[$group]) ? $group : null;
        $actors = $this->reader->actors($context);
        $actorRaw = WorkspaceRequest::text($request->input('actor'));
        $actorId = ctype_digit($actorRaw) && isset($actors[(int) $actorRaw]) ? (int) $actorRaw : null;
        $zone = new DateTimeZone($context->timezone);
        $fromRaw = WorkspaceRequest::text($request->input('from'));
        $toRaw = WorkspaceRequest::text($request->input('to'));
        $localFrom = $this->day($fromRaw, $zone);
        $localTo = $this->day($toRaw, $zone)?->modify('+1 day');
        $from = $localFrom?->setTimezone(new DateTimeZone('UTC'));
        $to = $localTo?->setTimezone(new DateTimeZone('UTC'));
        $pageRaw = $request->input('page');
        $result = $this->reader->page($context, $group, $actorId, $from, $to, is_string($pageRaw) && ctype_digit($pageRaw) ? (int) $pageRaw : 1);

        $query = array_filter([
            'group' => $group,
            'actor' => $actorId === null ? null : (string) $actorId,
            'from' => $from === null ? null : $fromRaw,
            'to' => $to === null ? null : $toRaw,
        ], static fn (?string $v): bool => $v !== null);

        return $this->view->response('workspace/audit.twig', [
            'workspace' => $context,
            'result' => $result,
            'groups' => AuditActions::groups(),
            'actors' => $actors,
            'filters' => ['group' => $group ?? '', 'actor' => $actorId === null ? '' : (string) $actorId, 'from' => $from === null ? '' : $fromRaw, 'to' => $to === null ? '' : $toRaw],
            'base' => '/w/' . $context->workspacePublicId . '/audit' . ($query === [] ? '' : '?' . http_build_query($query)),
            'zone' => $zone->getName(),
        ]);
    }

    /**
     * Start of the given day (YYYY-MM-DD) in the workspace's time zone, or null for anything else.
     */
    private function day(string $value, DateTimeZone $zone): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            return null;
        }

        return $date;
    }
}
