<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\AdminDirectory;
use App\Domain\Billing\BillingPeriod;
use App\Domain\Billing\PlanRepository;
use App\Domain\Billing\SubscriptionService;
use App\Domain\Workspace\WorkspaceRepository;
use App\Http\FormFlash;
use App\Http\WorkspaceRequest;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;

/**
 * Admin: workspaces with their owner, plan, members and channels; giving a plan by hand (a gift or a manual fix after a support case).
 */
final class WorkspacesController
{
    public function __construct(
        private readonly View $view,
        private readonly AdminDirectory $directory,
        private readonly WorkspaceRepository $workspaces,
        private readonly PlanRepository $plans,
        private readonly SubscriptionService $subscriptions,
        private readonly FormFlash $flash,
    ) {
    }

    public function index(Request $request): Response
    {
        $query = WorkspaceRequest::text($request->input('q'));
        $page = $request->input('page');

        return $this->view->response('admin/workspaces/index.twig', [
            'q' => $query,
            'result' => $this->directory->workspaces($query, is_string($page) && ctype_digit($page) ? (int) $page : 1),
            'base' => '/admin/workspaces' . ($query === '' ? '' : '?q=' . rawurlencode($query)),
        ]);
    }

    public function show(string $publicId): Response
    {
        $workspace = $this->directory->workspace($publicId) ?? throw new HttpException(404, 'Not found');

        return $this->view->response('admin/workspaces/show.twig', [
            'workspace' => $workspace,
            'members' => $this->directory->workspaceMembers((int) $workspace['id']),
            'channels' => $this->directory->workspaceChannels((int) $workspace['id']),
            'plans' => $this->plans->all(),
        ]);
    }

    public function grant(Request $request, string $publicId): Response
    {
        $staff = WorkspaceRequest::user($request);
        $workspace = $this->workspaces->findByPublicId($publicId) ?? throw new HttpException(404, 'Not found');
        $plan = $this->plans->findByCode(WorkspaceRequest::text($request->input('plan')));
        $period = BillingPeriod::tryFrom(WorkspaceRequest::text($request->input('period')));
        if ($plan === null || $period === null) {
            $this->flash->toast('Выберите тариф и срок.', 'error');

            return Response::redirect('/admin/workspaces/' . $publicId);
        }
        $subscription = $this->subscriptions->grant($workspace->id, $plan, $period, $staff->id);
        $this->flash->toast('Тариф «' . $plan->name . '» выдан' . ($subscription->currentPeriodEnd === null ? '.' : ' до ' . $subscription->currentPeriodEnd->format('d.m.Y') . '.'));

        return Response::redirect('/admin/workspaces/' . $publicId);
    }
}
