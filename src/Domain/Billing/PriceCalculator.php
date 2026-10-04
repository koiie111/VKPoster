<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Support\RuDates;
use DateTimeImmutable;

/**
 * Turns "this workspace wants plan X for period P" into an amount and dates. Pure arithmetic on integers (kopecks), no database.
 *
 * - From Free, a trial or an expired period: the full price, a new period starts now.
 * - Same plan and period again: a renewal, only when the paid period is about to end (otherwise the money would be taken twice).
 * - A plan that costs more per month, or the same plan for a longer period, takes effect now: with the same period length the customer
 *   pays the price difference for the rest of the period (the period end stays); with another length a new period starts now and the
 *   unused part of the old one is credited.
 * - Everything cheaper is booked for the end of the paid period and costs nothing now.
 */
final class PriceCalculator
{
    /** The smallest amount a provider accepts: 1 ruble. */
    public const MIN_AMOUNT = 100;

    public function __construct(private readonly int $renewalLeadDays)
    {
    }

    /**
     * @throws BillingException
     */
    public function quote(?Subscription $subscription, ?Plan $current, Plan $target, BillingPeriod $period, DateTimeImmutable $now, string $currency = 'RUB'): Quote
    {
        if ($target->isFree()) {
            throw new BillingException('Тариф Free бесплатный, платить за него не нужно.');
        }
        $price = $target->priceFor($period, $currency);
        if ($price === null) {
            throw new BillingException('Для этого тарифа нет цены на выбранный срок.');
        }

        $end = $subscription?->currentPeriodEnd;
        $currentPeriod = $subscription?->period;
        if ($subscription === null || $current === null || $current->isFree() || !$subscription->hasPaidPeriod() || $end === null || $currentPeriod === null || $end <= $now) {
            return $this->fresh($target, $period, $price, $currency, $now);
        }

        if ($current->id === $target->id && $currentPeriod === $period) {
            $window = $end->modify(sprintf('-%d days', $this->renewalLeadDays));
            if ($now < $window && $subscription->status !== SubscriptionStatus::PastDue) {
                throw new BillingException('Тариф уже оплачен до ' . RuDates::dayMonth($end) . '. Продлить можно за ' . $this->renewalLeadDays . ' дня до конца срока.');
            }

            return new Quote(InvoiceKind::Renewal, $target, $period, $price, $price, $currency, $end, $period->addTo($end), self::describe($target, $period, 'Продление тарифа'));
        }

        $currentMonthly = $current->monthlyEquivalent($currentPeriod, $currency) ?? 0;
        $targetMonthly = $target->monthlyEquivalent($period, $currency) ?? 0;
        // The same plan only changes its length: longer takes effect now, shorter waits. Another plan: the dearer one takes effect now.
        $immediate = $current->id === $target->id ? $period->months() > $currentPeriod->months() : $targetMonthly > $currentMonthly;
        if (!$immediate) {
            return new Quote(InvoiceKind::Renewal, $target, $period, 0, $price, $currency, $end, $period->addTo($end), self::describe($target, $period, 'Переход на тариф'), false);
        }

        $start = $subscription->currentPeriodStart ?? $now;
        $total = max(1, $end->getTimestamp() - $start->getTimestamp());
        $remaining = max(0, min($total, $end->getTimestamp() - $now->getTimestamp()));
        if ($currentPeriod === $period) {
            $difference = max(0, $price - $subscription->priceAmount);
            $amount = max(self::MIN_AMOUNT, self::prorate($difference, $remaining, $total));

            return new Quote(InvoiceKind::Upgrade, $target, $period, $amount, $price, $currency, $now, $end, 'Переход на тариф «' . $target->name . '» до ' . RuDates::dayMonth($end));
        }
        $credit = self::prorate($subscription->priceAmount, $remaining, $total);
        $amount = max(self::MIN_AMOUNT, $price - $credit);

        return new Quote(InvoiceKind::New, $target, $period, $amount, $price, $currency, $now, $period->addTo($now), self::describe($target, $period, 'Тариф') . ' (с учётом неиспользованного остатка)');
    }

    private function fresh(Plan $target, BillingPeriod $period, int $price, string $currency, DateTimeImmutable $now): Quote
    {
        return new Quote(InvoiceKind::New, $target, $period, $price, $price, $currency, $now, $period->addTo($now), self::describe($target, $period, 'Тариф'));
    }

    /** Integer share, rounded to the nearest kopeck. */
    public static function prorate(int $amount, int $remaining, int $total): int
    {
        return intdiv($amount * $remaining + intdiv($total, 2), max(1, $total));
    }

    private static function describe(Plan $plan, BillingPeriod $period, string $prefix): string
    {
        return $prefix . ' «' . $plan->name . '» ' . $period->forLabel();
    }
}
