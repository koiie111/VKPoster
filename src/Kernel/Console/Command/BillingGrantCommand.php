<?php

declare(strict_types=1);

namespace App\Kernel\Console\Command;

use App\Domain\Billing\BillingPeriod;
use App\Domain\Billing\PlanRepository;
use App\Domain\Billing\SubscriptionService;
use App\Domain\Workspace\WorkspaceRepository;
use App\Kernel\Console\Command;
use App\Kernel\Console\Output;

/**
 * `billing:grant WORKSPACE_ID PLAN [month|year]`: give a workspace a plan without a payment (a gift, a manual fix, local testing).
 * The period runs from now; the action is written to the audit log.
 */
final class BillingGrantCommand implements Command
{
    public function __construct(
        private readonly WorkspaceRepository $workspaces,
        private readonly PlanRepository $plans,
        private readonly SubscriptionService $subscriptions,
    ) {
    }

    public function name(): string
    {
        return 'billing:grant';
    }

    public function description(): string
    {
        return 'Give a workspace a plan without payment: WORKSPACE_ID PLAN [month|year]';
    }

    public function run(array $args, Output $out): int
    {
        $workspace = $this->workspaces->findByPublicId($args[0] ?? '');
        $plan = $this->plans->findByCode($args[1] ?? '');
        $period = BillingPeriod::tryFrom($args[2] ?? 'month');
        if ($workspace === null || $plan === null || $period === null) {
            $out->error('Usage: billing:grant WORKSPACE_PUBLIC_ID PLAN_CODE [month|year]');

            return 1;
        }
        $subscription = $this->subscriptions->grant($workspace->id, $plan, $period);
        $out->line(sprintf('Workspace "%s" is now on "%s" until %s.', $workspace->name, $plan->name, $subscription->currentPeriodEnd?->format('Y-m-d') ?? 'further notice'));

        return 0;
    }
}
