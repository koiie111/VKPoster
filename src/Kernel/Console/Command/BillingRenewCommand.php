<?php

declare(strict_types=1);

namespace App\Kernel\Console\Command;

use App\Domain\Billing\RenewalService;
use App\Domain\Billing\SubscriptionRepository;
use App\Kernel\Console\Command;
use App\Kernel\Console\Output;

/**
 * `billing:renew [--force SUBSCRIPTION_ID]`: run the billing tick now (the scheduler does it hourly), or try to charge one subscription
 * at once, ignoring the schedule and a switched-off renewal (to check recurring payments by hand).
 */
final class BillingRenewCommand implements Command
{
    public function __construct(private readonly RenewalService $renewals, private readonly SubscriptionRepository $subscriptions)
    {
    }

    public function name(): string
    {
        return 'billing:renew';
    }

    public function description(): string
    {
        return 'Run the billing tick (renewals, trials, payments check) or force a renewal: --force SUBSCRIPTION_ID';
    }

    public function run(array $args, Output $out): int
    {
        $force = array_search('--force', $args, true);
        if ($force === false) {
            foreach ($this->renewals->tick() as $name => $count) {
                $out->line(sprintf('%-18s %d', $name, $count));
            }

            return 0;
        }
        $id = $args[$force + 1] ?? '';
        $subscription = $this->subscriptions->findByPublicId($id);
        if ($subscription === null) {
            $out->error('Subscription not found. Pass its public id: --force 01J...');

            return 1;
        }
        $out->line('Renewal result: ' . $this->renewals->renew($subscription, true));

        return 0;
    }
}
