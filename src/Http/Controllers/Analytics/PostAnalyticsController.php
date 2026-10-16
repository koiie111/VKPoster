<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Domain\Analytics\{PostAnalytics,AnalyticsCsv,AnalyticsPdf};
use App\Domain\Approval\GuestLinks;
use App\Domain\Billing\Entitlements;
use App\Http\WorkspaceRequest;
use App\Kernel\Http\{Request,Response};
use App\Kernel\View\View;

/** Thin analytics boundary: existing workspace permission or an existing Stage 14 guest capability. */
final class PostAnalyticsController
{
    public function __construct(private readonly PostAnalytics $analytics, private readonly Entitlements $plans, private readonly GuestLinks $links, private readonly AnalyticsPdf $pdf, private readonly View $view, private readonly \App\Domain\Analytics\Analytics $events, private readonly \App\Support\Clock $clock)
    {
    }
    public function show(Request $request): Response
    {
        return $this->output($request, 'html');
    }
    public function csv(Request $request): Response
    {
        return $this->output($request, 'csv');
    }
    public function pdf(Request $request): Response
    {
        return $this->output($request, 'pdf');
    }
    private function output(Request $request, string $format): Response
    {
        $ctx = WorkspaceRequest::context($request);
        if ($format !== 'html') {
            $this->feature($ctx->workspaceId, 'analytics_export');
        }
        $report = $this->analytics->report($ctx, WorkspaceRequest::text($request->query['from'] ?? ''), WorkspaceRequest::text($request->query['to'] ?? ''), WorkspaceRequest::text($request->query['channel'] ?? ''));
        if ($format === 'csv') {
            return new Response(200, AnalyticsCsv::render($report), ['Content-Type' => 'text/csv; charset=utf-8','Content-Disposition' => 'attachment; filename="analytics.csv"','Cache-Control' => 'no-store']);
        }
        if ($format === 'pdf') {
            return new Response(200, $this->pdf->render($this->view->render('workspace/analytics/print.twig', ['report' => $report])), ['Content-Type' => 'application/pdf','Content-Disposition' => 'attachment; filename="analytics.pdf"','Cache-Control' => 'no-store']);
        }
        $this->events->track('post_analytics_viewed', $ctx->userId, $ctx->workspaceId, [], 'post_analytics:'.$ctx->workspaceId.':'.$this->clock->now()->format('Y-m-d'));
        return $this->view->response('workspace/analytics/index.twig', ['report' => $report,'base' => '/w/'.$ctx->workspacePublicId.'/analytics','can_export' => $this->plans->hasFeature($ctx->workspaceId, 'analytics_export')])->withHeader('Cache-Control', 'no-store');
    }
    private function feature(int $workspace, string $feature): void
    {
        if (!$this->plans->hasFeature($workspace, $feature)) {
            throw new \App\Kernel\Exception\HttpException(403, 'Эта возможность недоступна на вашем тарифе.');
        }
    }
    public function guest(Request $request): Response
    {
        $token = WorkspaceRequest::text(($request->attribute('route_params') ?? [])['token'] ?? '');
        $r = $this->links->authorized($token);
        $this->feature($r['context']->workspaceId, 'client_reports');
        $posts = $this->links->posts($token);
        $report = $this->analytics->report($r['context'], WorkspaceRequest::text($request->query['from'] ?? ''), WorkspaceRequest::text($request->query['to'] ?? ''), null, array_map(static fn ($p): int => $p->id, $posts));
        return $this->view->response('guest/analytics.twig', ['report' => $report,'token' => $token])->withHeader('Cache-Control', 'no-store')->withHeader('Referrer-Policy', 'no-referrer');
    }
}
