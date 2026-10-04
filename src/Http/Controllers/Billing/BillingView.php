<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Domain\Billing\BillingException;
use App\Domain\Billing\BillingPeriod;
use App\Domain\Billing\BillingService;
use App\Domain\Billing\Entitlements;
use App\Domain\Billing\InvoiceKind;
use App\Domain\Billing\InvoiceRepository;
use App\Domain\Billing\InvoiceStatus;
use App\Domain\Billing\PaymentMethodRepository;
use App\Domain\Billing\PaymentRepository;
use App\Domain\Billing\PaymentStatus;
use App\Domain\Billing\Invoice;
use App\Domain\Billing\Plan;
use App\Domain\Billing\PlanPresenter;
use App\Domain\Billing\PlanRepository;
use App\Domain\Billing\Quote;
use App\Domain\Billing\Subscription;
use App\Domain\Billing\SubscriptionService;
use App\Domain\Billing\SubscriptionStatus;
use App\Domain\Media\MediaPresenter;
use App\Domain\Workspace\WorkspaceContext;
use App\Integrations\Payments\GatewayRegistry;
use App\Kernel\Config;
use App\Support\Clock;
use App\Support\Money;
use App\Support\RuDates;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Everything the billing pages need as plain arrays: the current plan and its state in words, usage meters, invoices, and the plan cards
 * with what choosing each one would do and cost.
 */
final class BillingView
{
    public function __construct(
        private readonly PlanRepository $plans,
        private readonly SubscriptionService $subscriptions,
        private readonly Entitlements $entitlements,
        private readonly InvoiceRepository $invoices,
        private readonly PaymentRepository $payments,
        private readonly PaymentMethodRepository $methods,
        private readonly BillingService $billing,
        private readonly GatewayRegistry $gateways,
        private readonly Config $config,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(WorkspaceContext $context): array
    {
        $now = $this->clock->now();
        $zone = new DateTimeZone($context->timezone);
        $subscription = $this->subscriptions->ensure($context->workspaceId);
        $plan = $this->plans->find($subscription->planId) ?? $this->plans->free();
        $pending = $subscription->pendingPlanId === null ? null : $this->plans->find($subscription->pendingPlanId);
        $method = $subscription->paymentMethodId === null ? null : $this->methods->find($subscription->paymentMethodId);
        $end = $subscription->currentPeriodEnd ?? $subscription->trialEndsAt;

        $invoices = [];
        foreach ($this->invoices->history($context->workspaceId, 30) as $invoice) {
            $invoices[] = [
                'id' => $invoice->publicId,
                'number' => $invoice->number,
                'description' => $invoice->description,
                'amount' => Money::format($invoice->amount, $invoice->currency),
                'date' => RuDates::dayMonth($invoice->createdAt->setTimezone($zone)) . ' ' . $invoice->createdAt->setTimezone($zone)->format('Y'),
                'paid' => $invoice->status === InvoiceStatus::Paid,
                'status' => $invoice->status->label(),
                'tone' => $invoice->status === InvoiceStatus::Paid ? 'ok' : 'warn',
                'pay_url' => $this->payUrl($context, $invoice),
            ];
        }

        $nextCharge = null;
        if ($subscription->autoRenews() && $subscription->currentPeriodEnd !== null) {
            $target = $pending ?? $plan;
            $period = $subscription->pendingPeriod ?? $subscription->period;
            $price = $period === null ? null : $target->priceFor($period, $subscription->currency);
            if ($price !== null) {
                $nextCharge = ['date' => self::day($subscription->currentPeriodEnd->setTimezone($zone)), 'amount' => Money::format($price, $subscription->currency), 'plan' => $target->name];
            }
        }

        return [
            'workspace' => $context,
            'plan' => $plan,
            'status' => $subscription->status,
            'status_label' => $subscription->status->label(),
            'status_tone' => match ($subscription->status) {
                SubscriptionStatus::Active => 'ok',
                SubscriptionStatus::Trialing => 'info',
                SubscriptionStatus::PastDue => 'warn',
            },
            'is_trial' => $subscription->isTrial(),
            'is_free' => $plan->isFree(),
            'period_label' => $subscription->period?->label(),
            'ends' => $end === null ? null : self::day($end->setTimezone($zone)),
            'days_left' => $end === null ? null : max(0, (int) ceil(($end->getTimestamp() - $now->getTimestamp()) / 86400)),
            'past_due' => $subscription->status === SubscriptionStatus::PastDue,
            'grace_until' => $subscription->currentPeriodEnd === null ? null : self::day($subscription->currentPeriodEnd->modify(sprintf('+%d days', $this->config->int('billing.renewal.grace_days', 3)))->setTimezone($zone)),
            'cancel_at_end' => $subscription->cancelAtPeriodEnd,
            'has_paid_period' => $subscription->hasPaidPeriod(),
            'pending' => $pending === null ? null : ['name' => $pending->name, 'period' => ($subscription->pendingPeriod ?? $subscription->period)?->label() ?? ''],
            'next_charge' => $nextCharge,
            'last_failure' => $subscription->lastFailure,
            'method' => $method !== null && $method->active ? $method->title : null,
            'usage' => $this->usage($context, $plan, $now),
            'invoices' => $invoices,
        ];
    }

    /**
     * @return list<array{label: string, used: string, limit: string, unlimited: bool, percent: int, over: bool}>
     */
    private function usage(WorkspaceContext $context, Plan $plan, DateTimeImmutable $now): array
    {
        $usage = $this->entitlements->usage($context->workspaceId, $now);
        $rows = [];
        $add = static function (string $label, int $used, ?int $limit, callable $format) use (&$rows): void {
            $rows[] = [
                'label' => $label,
                'used' => (string) $format($used),
                'limit' => $limit === null ? '' : (string) $format($limit),
                'unlimited' => $limit === null,
                'percent' => $limit === null || $limit <= 0 ? 0 : min(100, (int) round($used * 100 / $limit)),
                'over' => $limit !== null && $used > $limit,
            ];
        };
        $plain = static fn (int $n): string => (string) $n;
        $add('Каналы', $usage['channels']['used'], $usage['channels']['limit'], $plain);
        $add('Посты в этом месяце', $usage['posts']['used'], $usage['posts']['limit'], $plain);
        $add('Люди в команде', $usage['members']['used'], $usage['members']['limit'], $plain);
        $add('Место в медиатеке', $usage['storage']['used'], $usage['storage']['limit'], static fn (int $n): string => MediaPresenter::size($n));

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function plans(WorkspaceContext $context, BillingPeriod $period): array
    {
        $now = $this->clock->now();
        $zone = new DateTimeZone($context->timezone);
        $subscription = $this->subscriptions->ensure($context->workspaceId);
        $current = $this->plans->find($subscription->planId) ?? $this->plans->free();
        $currency = $this->config->string('billing.currency', 'RUB');
        $public = array_values(array_filter($this->plans->all(), static fn (Plan $p): bool => $p->isPublic));

        $cards = [];
        foreach ($public as $plan) {
            $price = $plan->priceFor($period, $currency);
            $cards[] = [
                'code' => $plan->code,
                'name' => $plan->name,
                'is_current' => $plan->id === $current->id,
                'free' => $plan->isFree(),
                'price' => $plan->isFree() ? '0 ₽' : ($price === null ? null : Money::format($price, $currency)),
                'per' => $plan->isFree() ? 'навсегда' : $period->perLabel(),
                'monthly' => !$plan->isFree() && $period === BillingPeriod::Year && $price !== null ? 'около ' . Money::format((int) round($price / 1200) * 100, $currency) . ' в месяц' : null,
                'highlights' => PlanPresenter::highlights($plan),
                'action' => $this->action($context, $subscription, $current, $plan, $period, $zone),
            ];
        }
        $yearSaving = null;
        foreach ($public as $plan) {
            $month = $plan->priceFor(BillingPeriod::Month, $currency);
            $year = $plan->priceFor(BillingPeriod::Year, $currency);
            if ($month !== null && $year !== null && $month > 0) {
                $yearSaving = max((int) $yearSaving, (int) round((1 - $year / ($month * 12)) * 100));
            }
        }

        $gateways = [];
        foreach ($this->gateways->available() as $gateway) {
            $gateways[$gateway->name()] = $gateway->label();
        }

        return [
            'workspace' => $context,
            'cards' => $cards,
            'rows' => PlanPresenter::rows($public, $currency),
            'columns' => array_map(static fn (Plan $p): array => ['code' => $p->code, 'name' => $p->name, 'current' => $p->id === $current->id], $public),
            'period' => $period->value,
            'year_saving' => $yearSaving,
            'gateways' => $gateways,
            'is_trial' => $subscription->isTrial(),
            'trial_ends' => $subscription->trialEndsAt === null ? null : self::day($subscription->trialEndsAt->setTimezone($zone)),
        ];
    }

    /**
     * What the button of a plan card says and does.
     *
     * @return array{label: string, note: string|null, disabled: bool, primary: bool}
     */
    private function action(WorkspaceContext $context, Subscription $subscription, Plan $current, Plan $plan, BillingPeriod $period, DateTimeZone $zone): array
    {
        if ($plan->isFree()) {
            if ($current->isFree()) {
                return ['label' => 'Ваш тариф', 'note' => null, 'disabled' => true, 'primary' => false];
            }

            return ['label' => 'Бесплатный тариф', 'note' => $subscription->isTrial() ? 'Пробный период закончится сам, и пространство перейдёт на Free.' : 'Чтобы вернуться на Free, отключите автопродление: тариф доработает до конца срока.', 'disabled' => true, 'primary' => false];
        }
        if ($plan->priceFor($period, $this->config->string('billing.currency', 'RUB')) === null) {
            return ['label' => 'Нет цены на этот срок', 'note' => null, 'disabled' => true, 'primary' => false];
        }
        try {
            $quote = $this->billing->quote($context->workspaceId, $plan->code, $period);
        } catch (BillingException $e) {
            $same = $plan->id === $current->id;

            return ['label' => $same ? 'Ваш тариф' : 'Недоступно', 'note' => $e->getMessage(), 'disabled' => true, 'primary' => false];
        }

        return $this->describe($quote, $subscription, $zone);
    }

    /**
     * @return array{label: string, note: string|null, disabled: bool, primary: bool}
     */
    private function describe(Quote $quote, Subscription $subscription, DateTimeZone $zone): array
    {
        if (!$quote->immediate) {
            return ['label' => 'Перейти с ' . RuDates::dayMonth($quote->periodStart->setTimezone($zone)), 'note' => 'Сейчас платить не нужно: тариф начнётся, когда закончится оплаченный срок.', 'disabled' => false, 'primary' => false];
        }
        $amount = Money::format($quote->amount, $quote->currency);

        return match ($quote->kind) {
            InvoiceKind::Upgrade => ['label' => 'Доплатить ' . $amount, 'note' => 'Доплата за остаток текущего срока. Срок не меняется.', 'disabled' => false, 'primary' => true],
            InvoiceKind::Renewal => ['label' => 'Продлить за ' . $amount, 'note' => null, 'disabled' => false, 'primary' => true],
            default => ['label' => 'Оплатить ' . $amount, 'note' => $subscription->isTrial() ? 'Оплаченный срок начнётся сегодня, остаток пробного периода не переносится.' : null, 'disabled' => false, 'primary' => true],
        };
    }

    /** Link to finish an unpaid bill: only for an open invoice that still has a payment waiting at the provider. */
    private function payUrl(WorkspaceContext $context, Invoice $invoice): ?string
    {
        if ($invoice->status !== InvoiceStatus::Open || $invoice->expiresAt <= $this->clock->now()) {
            return null;
        }
        foreach ($this->payments->forInvoice($invoice->id) as $payment) {
            if ($payment->status === PaymentStatus::Pending && $payment->confirmationUrl !== null) {
                return '/w/' . $context->workspacePublicId . '/billing/pay/' . $payment->publicId;
            }
        }

        return null;
    }

    private static function day(DateTimeImmutable $at): string
    {
        return RuDates::dayMonth($at) . ' ' . $at->format('Y');
    }
}
