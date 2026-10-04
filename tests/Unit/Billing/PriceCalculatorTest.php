<?php

declare(strict_types=1);

namespace App\Tests\Unit\Billing;

use App\Domain\Billing\BillingException;
use App\Domain\Billing\BillingPeriod;
use App\Domain\Billing\InvoiceKind;
use App\Domain\Billing\Plan;
use App\Domain\Billing\PriceCalculator;
use App\Domain\Billing\Subscription;
use App\Domain\Billing\SubscriptionStatus;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The arithmetic of plan changes: what is charged, from when to when, and what is only booked for later.
 */
#[CoversClass(PriceCalculator::class)]
final class PriceCalculatorTest extends TestCase
{
    private PriceCalculator $calc;
    private Plan $free;
    private Plan $start;
    private Plan $pro;

    protected function setUp(): void
    {
        $this->calc = new PriceCalculator(3);
        $this->free = new Plan(1, 'free', 'Free', 0, [], [], [], true);
        $this->start = new Plan(2, 'start', 'Старт', 1, [], [], ['month' => ['RUB' => 39000], 'year' => ['RUB' => 390000]], true);
        $this->pro = new Plan(3, 'pro', 'Про', 2, [], [], ['month' => ['RUB' => 99000], 'year' => ['RUB' => 990000]], true);
    }

    private function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone('UTC'));
    }

    private function paid(Plan $plan, BillingPeriod $period, string $start, string $end, int $price, SubscriptionStatus $status = SubscriptionStatus::Active): Subscription
    {
        return new Subscription(1, 'S', 1, $plan->id, $status, $period, 'RUB', $price, $this->at($start), $this->at($end), null, null, false, null, null, null, 0, null, null, null, $this->at($start));
    }

    private function trial(): Subscription
    {
        return new Subscription(1, 'S', 1, $this->pro->id, SubscriptionStatus::Trialing, null, 'RUB', 0, null, null, $this->at('2026-10-10'), null, false, null, null, null, 0, null, null, null, $this->at('2026-09-26'));
    }

    public function testFromFreeTheFullPriceStartsANewPeriodNow(): void
    {
        $now = $this->at('2026-10-04 12:00:00');

        $quote = $this->calc->quote(null, $this->free, $this->pro, BillingPeriod::Month, $now);

        self::assertSame(InvoiceKind::New, $quote->kind);
        self::assertSame(99000, $quote->amount);
        self::assertSame(99000, $quote->listPrice);
        self::assertTrue($quote->immediate);
        self::assertEquals($now, $quote->periodStart);
        self::assertSame('2026-11-04 12:00:00', $quote->periodEnd->format('Y-m-d H:i:s'));
    }

    public function testAYearCostsTheYearlyPrice(): void
    {
        $quote = $this->calc->quote(null, $this->free, $this->pro, BillingPeriod::Year, $this->at('2026-10-04 12:00:00'));

        self::assertSame(990000, $quote->amount);
        self::assertSame('2027-10-04 12:00:00', $quote->periodEnd->format('Y-m-d H:i:s'));
    }

    public function testTheTrialIsNotCreditedAndThePaidPeriodStartsToday(): void
    {
        $quote = $this->calc->quote($this->trial(), $this->pro, $this->start, BillingPeriod::Month, $this->at('2026-10-04 12:00:00'));

        self::assertSame(InvoiceKind::New, $quote->kind);
        self::assertSame(39000, $quote->amount);
    }

    public function testUpgradeInTheMiddleOfAPeriodChargesTheDifferenceForTheRest(): void
    {
        // A 30-day period (Start, paid 390 ₽), half of it gone: Pro costs 990, so the difference of 600 ₽ is charged for the remaining half.
        $subscription = $this->paid($this->start, BillingPeriod::Month, '2026-10-01 00:00:00', '2026-10-31 00:00:00', 39000);

        $quote = $this->calc->quote($subscription, $this->start, $this->pro, BillingPeriod::Month, $this->at('2026-10-16 00:00:00'));

        self::assertSame(InvoiceKind::Upgrade, $quote->kind);
        self::assertSame(30000, $quote->amount, 'half of the 600 ₽ difference');
        self::assertSame(99000, $quote->listPrice);
        self::assertSame('2026-10-31 00:00:00', $quote->periodEnd->format('Y-m-d H:i:s'), 'the paid period does not move');
        self::assertTrue($quote->immediate);
    }

    public function testUpgradeAtTheVeryEndNeverCostsLessThanAMinimalPayment(): void
    {
        $subscription = $this->paid($this->start, BillingPeriod::Month, '2026-10-01 00:00:00', '2026-10-31 00:00:00', 39000);

        $quote = $this->calc->quote($subscription, $this->start, $this->pro, BillingPeriod::Month, $this->at('2026-10-30 23:59:59'));

        self::assertSame(PriceCalculator::MIN_AMOUNT, $quote->amount);
    }

    public function testDowngradeIsBookedForTheEndAndCostsNothingNow(): void
    {
        $subscription = $this->paid($this->pro, BillingPeriod::Month, '2026-10-01 00:00:00', '2026-10-31 00:00:00', 99000);

        $quote = $this->calc->quote($subscription, $this->pro, $this->start, BillingPeriod::Month, $this->at('2026-10-10 00:00:00'));

        self::assertFalse($quote->immediate);
        self::assertSame(0, $quote->amount);
        self::assertSame('2026-10-31 00:00:00', $quote->periodStart->format('Y-m-d H:i:s'));
    }

    public function testSwitchingToAYearOfTheSamePlanStartsANewPeriodWithACreditForTheUnusedRest(): void
    {
        $subscription = $this->paid($this->pro, BillingPeriod::Month, '2026-10-01 00:00:00', '2026-10-31 00:00:00', 99000);

        $quote = $this->calc->quote($subscription, $this->pro, $this->pro, BillingPeriod::Year, $this->at('2026-10-16 00:00:00'));

        self::assertSame(InvoiceKind::New, $quote->kind);
        self::assertSame(990000 - 49500, $quote->amount, 'the year minus half of the month already paid for');
        self::assertSame('2027-10-16 00:00:00', $quote->periodEnd->format('Y-m-d H:i:s'));
    }

    public function testSwitchingFromAYearToAMonthIsBooked(): void
    {
        $subscription = $this->paid($this->pro, BillingPeriod::Year, '2026-01-01 00:00:00', '2027-01-01 00:00:00', 990000);

        $quote = $this->calc->quote($subscription, $this->pro, $this->pro, BillingPeriod::Month, $this->at('2026-10-10 00:00:00'));

        self::assertFalse($quote->immediate);
    }

    public function testRenewalIsAllowedOnlyInTheLastDays(): void
    {
        $subscription = $this->paid($this->pro, BillingPeriod::Month, '2026-10-01 00:00:00', '2026-10-31 00:00:00', 99000);

        $early = fn () => $this->calc->quote($subscription, $this->pro, $this->pro, BillingPeriod::Month, $this->at('2026-10-10 00:00:00'));
        try {
            $early();
            self::fail('an early renewal would charge twice for the same days');
        } catch (BillingException $e) {
            self::assertStringContainsString('уже оплачен до', $e->getMessage());
        }

        $quote = $this->calc->quote($subscription, $this->pro, $this->pro, BillingPeriod::Month, $this->at('2026-10-29 00:00:00'));
        self::assertSame(InvoiceKind::Renewal, $quote->kind);
        self::assertSame(99000, $quote->amount);
        self::assertSame('2026-10-31 00:00:00', $quote->periodStart->format('Y-m-d H:i:s'), 'the new period follows the paid one');
        self::assertSame('2026-11-30 00:00:00', $quote->periodEnd->format('Y-m-d H:i:s'));
    }

    public function testAPastDueSubscriptionMayAlwaysBeRenewed(): void
    {
        $subscription = $this->paid($this->pro, BillingPeriod::Month, '2026-10-01 00:00:00', '2026-10-31 00:00:00', 99000, SubscriptionStatus::PastDue);

        $quote = $this->calc->quote($subscription, $this->pro, $this->pro, BillingPeriod::Month, $this->at('2026-10-10 00:00:00'));

        self::assertSame(InvoiceKind::Renewal, $quote->kind);
    }

    public function testAnEndedPeriodIsTreatedLikeAFreshPurchase(): void
    {
        $subscription = $this->paid($this->pro, BillingPeriod::Month, '2026-09-01 00:00:00', '2026-10-01 00:00:00', 99000, SubscriptionStatus::PastDue);
        $now = $this->at('2026-10-02 00:00:00');

        $quote = $this->calc->quote($subscription, $this->pro, $this->start, BillingPeriod::Month, $now);

        self::assertSame(InvoiceKind::New, $quote->kind);
        self::assertSame(39000, $quote->amount);
        self::assertEquals($now, $quote->periodStart);
    }

    public function testFreeAndUnpricedPlansCannotBeBought(): void
    {
        $now = $this->at('2026-10-04 00:00:00');

        $this->expectException(BillingException::class);
        $this->calc->quote(null, $this->free, $this->free, BillingPeriod::Month, $now);
    }

    public function testAPlanWithoutAPriceForThePeriodIsRefused(): void
    {
        $monthOnly = new Plan(9, 'm', 'M', 3, [], [], ['month' => ['RUB' => 1000]], true);

        $this->expectException(BillingException::class);
        $this->calc->quote(null, $this->free, $monthOnly, BillingPeriod::Year, $this->at('2026-10-04 00:00:00'));
    }

    public function testProrationRoundsToTheNearestKopeck(): void
    {
        self::assertSame(33, PriceCalculator::prorate(100, 1, 3));
        self::assertSame(67, PriceCalculator::prorate(100, 2, 3));
        self::assertSame(0, PriceCalculator::prorate(100, 0, 3));
        self::assertSame(100, PriceCalculator::prorate(100, 3, 3));
    }
}
