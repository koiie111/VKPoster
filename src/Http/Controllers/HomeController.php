<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Billing\BillingPeriod;
use App\Domain\Content\SiteContent;
use App\Domain\Billing\Plan;
use App\Domain\Billing\PlanPresenter;
use App\Domain\Billing\PlanRepository;
use App\Kernel\Config;
use App\Kernel\Http\Response;
use App\Kernel\View\View;
use App\Support\Money;

/**
 * The landing page. Prices and limits come from the `plans` table, so a price changed in the admin area shows up here at once.
 */
final class HomeController
{
    public function __construct(
        private readonly View $view,
        private readonly PlanRepository $plans,
        private readonly Config $config,
        private readonly SiteContent $content,
    ) {
    }

    public function index(): Response
    {
        $trialDays = $this->config->int('billing.trial.days', 14);
        $trialPlan = $this->config->string('billing.trial.plan', 'pro');
        $cards = [];
        foreach ($this->plans->all() as $plan) {
            if (!$plan->isPublic) {
                continue;
            }
            $cards[] = $this->card($plan, $plan->code === $trialPlan);
        }
        $faq = [];
        /** @var list<array{0: string, 1: string}> $items */
        $items = $this->content->faq() ?? require dirname(__DIR__, 3) . '/resources/site/faq.php';
        foreach ($items as [$question, $answer]) {
            $faq[] = ['q' => $question, 'a' => str_replace('{trial_days}', (string) $trialDays, $answer)];
        }

        return $this->view->response('site/home.twig', [
            'plans' => $cards,
            'faq' => $faq,
            'trial_days' => $trialDays,
            'indexable' => true,
            'seo_title' => $this->config->string('app.name') . ': отложенный постинг в Telegram, ВКонтакте и MAX',
            'seo_description' => 'Пишите посты заранее, а ' . $this->config->string('app.name') . ' опубликует их вовремя в Telegram, ВКонтакте и MAX. Календарь, превью для каждой сети, команда, ' . $trialDays . ' дней бесплатно.',
            'canonical_path' => '/',
        ]);
    }

    /**
     * @return array{code: string, name: string, price: string, per: string, year: string|null, highlights: list<string>, popular: bool, free: bool}
     */
    private function card(Plan $plan, bool $popular): array
    {
        $month = $plan->priceFor(BillingPeriod::Month);
        $year = $plan->priceFor(BillingPeriod::Year);
        $saving = $month !== null && $year !== null ? $month * 12 - $year : 0;

        return [
            'code' => $plan->code,
            'name' => $plan->name,
            'price' => $month === null ? '0 ₽' : Money::format($month),
            'per' => $month === null ? 'навсегда' : 'в месяц',
            'year' => $year === null ? null : 'или ' . Money::format($year) . ' в год' . ($saving > 0 ? ', выгода ' . Money::format($saving) : ''),
            'highlights' => PlanPresenter::highlights($plan),
            'popular' => $popular,
            'free' => $plan->isFree(),
        ];
    }
}
