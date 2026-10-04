<?php

declare(strict_types=1);

namespace App\Kernel\Console\Command;

use App\Domain\Billing\PlanCatalog;
use App\Domain\Billing\PlanRepository;
use App\Kernel\Config;
use App\Kernel\Console\Command;
use App\Kernel\Console\Output;
use App\Kernel\Database\Connection;
use App\Support\Money;

/**
 * `billing:plans [--sync]`: print the price list from the database; with `--sync` first add plans (and prices) that exist in
 * `config/billing.php` but not in the database yet. Existing plans are never overwritten.
 */
final class BillingPlansCommand implements Command
{
    public function __construct(private readonly Connection $db, private readonly PlanRepository $plans, private readonly Config $config)
    {
    }

    public function name(): string
    {
        return 'billing:plans';
    }

    public function description(): string
    {
        return 'Show the plans and prices; --sync adds plans missing from the database';
    }

    public function run(array $args, Output $out): int
    {
        if (in_array('--sync', $args, true)) {
            $added = PlanCatalog::insertMissing($this->db, $this->config->array('billing.catalog'), $this->config->string('billing.currency', 'RUB'));
            $out->line(sprintf('Added %d plan(s).', $added));
        }
        foreach ($this->plans->all() as $plan) {
            $prices = [];
            foreach ($plan->prices as $period => $byCurrency) {
                foreach ($byCurrency as $currency => $amount) {
                    $prices[] = $period . ' ' . Money::format($amount, $currency);
                }
            }
            $out->line(sprintf('%-8s %-10s %s', $plan->code, $plan->name, $prices === [] ? 'free' : implode(', ', $prices)));
        }

        return 0;
    }
}
