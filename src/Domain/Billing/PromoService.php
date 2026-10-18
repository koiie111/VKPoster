<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Audit\AuditLog;
use App\Support\Clock;

/**
 * Checks a promo code for one purchase and works out the discount. The rules: the code exists and is switched on, is inside its dates and
 * has uses left, fits the plan and the currency, is the person's first use of it, and (for "first payment only" codes) the workspace has
 * never paid. A discount never takes the price below the smallest charge providers accept. Free-day codes give a plan without a payment
 * and only to a workspace that has no paid period running.
 */
final class PromoService
{
    public function __construct(
        private readonly PromoRepository $promos,
        private readonly PlanRepository $plans,
        private readonly SubscriptionService $subscriptionService,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
    ) {
    }

    public static function normalize(string $code): string
    {
        return strtoupper(trim($code));
    }

    public static function isValidCode(string $code): bool
    {
        return preg_match('/^[A-Z0-9_-]{3,32}$/', $code) === 1;
    }

    /**
     * The code if it may be used for this purchase.
     *
     * @throws BillingException with a message for the customer
     */
    public function check(string $rawCode, int $userId, int $workspaceId, Plan $plan, string $currency): Promo
    {
        $code = self::normalize($rawCode);
        // One message for "no such code" and "not yours to use": a guess teaches nothing.
        $promo = self::isValidCode($code) ? $this->promos->findByCode($code) : null;
        $now = $this->clock->now();
        if ($promo === null || !$promo->active) {
            throw new BillingException('Такого промокода нет или он уже не действует.');
        }
        if (($promo->validFrom !== null && $now < $promo->validFrom) || ($promo->validUntil !== null && $now > $promo->validUntil)) {
            throw new BillingException('Срок действия промокода закончился или ещё не начался.');
        }
        if ($promo->planCodes !== [] && !in_array($plan->code, $promo->planCodes, true)) {
            throw new BillingException('Этот промокод не подходит к выбранному тарифу.');
        }
        if ($promo->kind === 'amount' && $promo->currency !== $currency) {
            throw new BillingException('Этот промокод действует при оплате в другой валюте.');
        }
        if ($promo->maxRedemptions !== null && $this->promos->used($promo->id) >= $promo->maxRedemptions) {
            throw new BillingException('Этот промокод уже использовали максимальное число раз.');
        }
        if ($this->promos->usedBy($promo->id, $userId)) {
            throw new BillingException('Вы уже использовали этот промокод.');
        }
        if ($promo->firstPaymentOnly && $this->promos->hasPaid($workspaceId)) {
            throw new BillingException('Этот промокод действует только на первую оплату.');
        }

        return $promo;
    }

    /**
     * The discount of a code on an amount. Never leaves less than the smallest charge.
     */
    public function discount(Promo $promo, int $amount): int
    {
        $off = match ($promo->kind) {
            'percent' => intdiv($amount * min(100, $promo->value) + 50, 100),
            'amount' => $promo->value,
            default => 0,
        };

        return max(0, min($off, $amount - PriceCalculator::MIN_AMOUNT));
    }

    /**
     * The quote with a promo code taken off. A quote that costs nothing now (a change booked for later) is returned as it is.
     */
    public function apply(Quote $quote, Promo $promo): Quote
    {
        if (!$quote->immediate || $promo->kind === 'days') {
            throw new BillingException('Этот промокод нельзя применить к такой оплате.');
        }
        $off = $this->discount($promo, $quote->amount);
        if ($off <= 0) {
            throw new BillingException('Этот промокод не даёт скидки на такую сумму.');
        }

        return new Quote($quote->kind, $quote->plan, $quote->period, $quote->amount - $off, $quote->listPrice, $quote->currency, $quote->periodStart, $quote->periodEnd, $quote->description . ' (промокод ' . $promo->code . ')', true, $off, $promo->id);
    }

    /**
     * Activate a free-days code: the plan for N days, no payment. Only for a workspace without a paid period running.
     *
     * @throws BillingException
     */
    public function activateFreeDays(int $workspaceId, int $userId, string $rawCode): Promo
    {
        $code = self::normalize($rawCode);
        $promo = self::isValidCode($code) ? $this->promos->findByCode($code) : null;
        if ($promo === null || $promo->kind !== 'days' || $promo->planCodes === []) {
            throw new BillingException('Такого промокода нет или он уже не действует.');
        }
        $plan = $this->plans->findByCode($promo->planCodes[0]) ?? throw new BillingException('Тариф этого промокода больше не продаётся.');
        $this->check($code, $userId, $workspaceId, $plan, 'RUB');
        $subscription = $this->subscriptionService->ensure($workspaceId);
        if ($subscription->hasPaidPeriod() && $subscription->currentPeriodEnd !== null && $subscription->currentPeriodEnd > $this->clock->now()) {
            throw new BillingException('У вас уже оплачен тариф. Бесплатные дни можно получить, когда оплаченный срок закончится.');
        }
        $this->subscriptionService->grantDays($workspaceId, $plan, $promo->value, $userId);
        $this->promos->redeem($promo, $workspaceId, $userId, null, $promo->value);
        $this->audit->record('billing.promo_days_activated', $userId, 'promo', $promo->code, ['plan' => $plan->code, 'days' => $promo->value], $workspaceId);

        return $promo;
    }

    /**
     * Remember that an invoice was created with a code (the use counts until the invoice is paid, voided or expires).
     */
    public function bind(Promo $promo, Invoice $invoice, int $userId): void
    {
        $this->promos->redeem($promo, $invoice->workspaceId, $userId, $invoice->id, $invoice->discount);
    }
}
