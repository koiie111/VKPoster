<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Billing\Entitlements;
use App\Domain\Workspace\WorkspaceContext;
use App\Http\FormFlash;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\Middleware\MiddlewareInterface;
use Closure;

/**
 * Guards a page that belongs to a plan feature, e.g. `[PlanFeature::class, ['feature' => 'approvals', 'name' => 'Согласование постов']]`.
 * A workspace whose plan lacks the feature is sent to the plan comparison with a clear message (JSON clients get 402 and the same text).
 * Services check the feature again themselves: this only spares the person a page that cannot work.
 * Must run after `ResolveWorkspace`.
 */
final class PlanFeature implements MiddlewareInterface
{
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly FormFlash $flash,
        private readonly string $feature,
        private readonly string $name = '',
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $workspace = $request->attribute('workspace');
        if (!$workspace instanceof WorkspaceContext) {
            throw new HttpException(404, 'Not found');
        }
        if ($this->entitlements->hasFeature($workspace->workspaceId, $this->feature)) {
            return $next($request);
        }
        $plan = $this->entitlements->plan($workspace->workspaceId);
        $message = sprintf('«%s» недоступно на тарифе «%s». Выберите тариф, в который эта возможность входит.', $this->name !== '' ? $this->name : $this->feature, $plan->name);
        if ($request->wantsJson()) {
            return Response::json(['ok' => false, 'message' => $message, 'upgrade_url' => '/w/' . $workspace->workspacePublicId . '/billing/plans'], 402);
        }
        $this->flash->planLimit($message, $workspace->workspacePublicId);

        return Response::redirect('/w/' . $workspace->workspacePublicId . '/billing/plans');
    }
}
