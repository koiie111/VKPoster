<?php

declare(strict_types=1);

namespace App\Tests\Integration\Billing;

use App\Domain\Billing\BillingPeriod;
use App\Domain\Billing\InvoiceKind;
use App\Domain\Billing\InvoiceStatus;
use App\Domain\Billing\PaymentMethodRepository;
use App\Domain\Billing\RenewalService;
use App\Domain\Billing\SubscriptionStatus;
use App\Domain\Channel\ChannelRepository;
use App\Domain\Channel\ChannelStatus;
use App\Integrations\Payments\Fake\FakeGateway;
use App\Support\DbTime;
use App\Tests\Support\BillingFixtures;
use App\Tests\Support\BillingTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The billing tick: renewals and their retries, the grace period, trials, lost notifications. The clock is moved instead of waiting.
 */
#[CoversClass(RenewalService::class)]
final class RenewalServiceTest extends BillingTestCase
{
    private function setClockTo(?\DateTimeImmutable $time): void
    {
        $this->clock->set(($time ?? self::fail('no time'))->format('Y-m-d H:i:s'));
    }

    /**
     * @return list<string>
     */
    private function subjects(): array
    {
        return $this->mailSubjects();
    }

    public function testTheCardIsChargedThreeDaysBeforeTheEndAndNotEarlier(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->payWithFake($workspace, $owner, 'pro');
        $end = $this->subscription($workspace)->currentPeriodEnd;

        $this->setClockTo($end?->modify('-3 days -1 minute'));
        $early = $this->renewals()->tick();
        self::assertSame(0, $early['renewals_paid']);
        self::assertEquals($end, $this->subscription($workspace)->currentPeriodEnd);

        $this->setClockTo($end?->modify('-3 days'));
        $summary = $this->renewals()->tick();

        self::assertSame(1, $summary['renewals_paid']);
        $s = $this->subscription($workspace);
        self::assertSame($end?->modify('+1 month')->format('Y-m-d H:i'), $s->currentPeriodEnd?->format('Y-m-d H:i'), 'the new period follows the old one');
        self::assertSame($end?->format('Y-m-d H:i'), $s->currentPeriodStart?->format('Y-m-d H:i'));
        self::assertSame(SubscriptionStatus::Active, $s->status);
        self::assertSame($s->currentPeriodEnd?->modify('-3 days')->format('Y-m-d H:i'), $s->nextRenewalAttemptAt?->format('Y-m-d H:i'));
        $kinds = array_map(static fn (array $r): string => (string) $r['kind'] . ':' . $r['status'], $this->db->select('SELECT kind, status FROM invoices ORDER BY id'));
        self::assertSame(['new:paid', 'renewal:paid'], $kinds);
        self::assertSame(2 * 99000, $this->app->container()->get(\App\Domain\Billing\Ledger::class)->balance('provider:fake'));
        self::assertSame(2, array_count_values($this->subjects())['Оплата получена: тариф «Про»']);
    }

    public function testRunningTheTickAgainDoesNotChargeTwice(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->payWithFake($workspace, $owner, 'pro');
        $this->setClockTo($this->subscription($workspace)->currentPeriodEnd?->modify('-3 days'));
        $this->renewals()->tick();

        $second = $this->renewals()->tick();

        self::assertSame(0, $second['renewals_paid']);
        self::assertSame(2, (int) $this->db->select('SELECT COUNT(*) AS c FROM invoices')[0]['c']);
    }

    public function testADecliningCardIsRetriedOnTheFirstAndThirdDayThenTheGraceStartsAndTheWorkspaceFallsToFree(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        for ($i = 1; $i <= 4; ++$i) {
            $this->fakeChannel($workspace, $owner, 'fake-' . $i, 'Канал ' . $i);
            $this->clock->advance(5);
        }
        $this->payWithFake($workspace, $owner, 'pro', answer: 'pay_declining');
        $end = $this->subscription($workspace)->currentPeriodEnd ?? self::fail('end');
        $first = $end->modify('-3 days');

        // Attempt 1: three days before the end.
        $this->setClockTo($first);
        self::assertSame(1, $this->renewals()->tick()['renewals_failed']);
        $s = $this->subscription($workspace);
        self::assertSame(1, $s->renewalAttempts);
        self::assertSame($first->modify('+1 day')->format('Y-m-d H:i'), $s->nextRenewalAttemptAt?->format('Y-m-d H:i'), 'the first retry is a day later');
        self::assertSame('Банк отклонил списание (insufficient_funds).', $s->lastFailure);
        self::assertSame($end->format('Y-m-d H:i'), $s->currentPeriodEnd?->format('Y-m-d H:i'), 'nothing is extended');
        self::assertSame(SubscriptionStatus::Active, $s->status);

        // Attempt 2: a day after the first.
        $this->setClockTo($first->modify('+1 day'));
        self::assertSame(1, $this->renewals()->tick()['renewals_failed']);
        self::assertSame($first->modify('+3 days')->format('Y-m-d H:i'), $this->subscription($workspace)->nextRenewalAttemptAt?->format('Y-m-d H:i'), 'the last retry is on the third day');

        // Attempt 3: the day the period ends. No more attempts after it, and the grace period begins.
        $this->setClockTo($end);
        $summary = $this->renewals()->tick();
        self::assertSame(1, $summary['renewals_failed']);
        self::assertSame(1, $summary['past_due']);
        $s = $this->subscription($workspace);
        self::assertSame(3, $s->renewalAttempts);
        self::assertNull($s->nextRenewalAttemptAt, 'the ladder is over');
        self::assertSame(SubscriptionStatus::PastDue, $s->status);
        self::assertSame('pro', $this->plans()->find($s->planId)?->code, 'the plan still works during the grace period');

        // Nothing happens during the grace days.
        $this->setClockTo($end->modify('+2 days'));
        $quiet = $this->renewals()->tick();
        self::assertSame(0, $quiet['renewals_failed'] + $quiet['dropped_to_free']);

        // After three days of grace: Free, channels beyond Free's two paused, nothing deleted.
        $this->setClockTo($end->modify('+3 days'));
        self::assertSame(1, $this->renewals()->tick()['dropped_to_free']);
        $s = $this->subscription($workspace);
        self::assertSame('free', $this->plans()->find($s->planId)?->code);
        $context = $this->contextFor($workspace, $owner);
        $channels = $this->app->container()->get(ChannelRepository::class)->all($context);
        self::assertCount(4, $channels);
        self::assertSame(2, count(array_filter($channels, static fn ($c): bool => $c->status === ChannelStatus::Paused)));

        $subjects = $this->subjects();
        self::assertSame(3, count(array_filter($subjects, static fn (string $s): bool => $s === 'Не удалось продлить тариф «Про»')), 'an email for each failure');
        self::assertContains('Тариф отключён: не удалось получить оплату', $subjects);
        self::assertContains('billing.downgraded_to_free', $this->auditActions($workspace));
    }

    public function testPayingByHandDuringTheGraceStartsANewPeriodAndEndsTheGrace(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->payWithFake($workspace, $owner, 'pro', answer: 'pay_declining');
        $end = $this->subscription($workspace)->currentPeriodEnd ?? self::fail('end');
        $this->setClockTo($end->modify('-3 days'));
        $this->renewals()->tick();
        $this->setClockTo($end->modify('+1 hour'));
        $this->renewals()->tick();
        self::assertSame(SubscriptionStatus::PastDue, $this->subscription($workspace)->status);

        $this->payWithFake($workspace, $owner, 'pro');

        $s = $this->subscription($workspace);
        self::assertSame(SubscriptionStatus::Active, $s->status);
        self::assertSame($this->clock->now()->modify('+1 month')->format('Y-m-d H:i'), $s->currentPeriodEnd?->format('Y-m-d H:i'));
        self::assertSame(0, $s->renewalAttempts);
    }

    public function testWithoutACardAndWithRenewalSwitchedOnTheOwnerIsToldToPayByHand(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->payWithFake($workspace, $owner, 'pro');
        $s = $this->subscription($workspace);
        $this->subscriptions()->update($s->id, ['payment_method_id' => null, 'next_renewal_attempt_at' => DbTime::format($this->clock->now())]);

        $summary = $this->renewals()->tick();

        self::assertSame(1, $summary['renewals_failed']);
        self::assertContains('Пора продлить тариф «Про»', $this->subjects());
        self::assertStringContainsString('Нет сохранённой карты', (string) $this->subscription($workspace)->lastFailure);
    }

    public function testACancelledSubscriptionFallsToFreeTheMomentItsPeriodEndsWithoutGrace(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->payWithFake($workspace, $owner, 'pro');
        $this->subscriptionService()->cancelRenewal($workspace->id, $owner->id);
        $end = $this->subscription($workspace)->currentPeriodEnd ?? self::fail('end');

        $this->setClockTo($end->modify('-1 minute'));
        self::assertSame(0, $this->renewals()->tick()['dropped_to_free']);
        self::assertSame('pro', $this->plans()->find($this->subscription($workspace)->planId)?->code, 'it works to the last minute that was paid for');

        $this->setClockTo($end);
        self::assertSame(1, $this->renewals()->tick()['dropped_to_free']);
        self::assertSame('free', $this->plans()->find($this->subscription($workspace)->planId)?->code);
        self::assertContains('Подписка закончилась', $this->subjects());
    }

    public function testACancelledSubscriptionIsNotCharged(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->payWithFake($workspace, $owner, 'pro');
        $this->subscriptionService()->cancelRenewal($workspace->id, $owner->id);
        $this->subscriptions()->update($this->subscription($workspace)->id, ['next_renewal_attempt_at' => DbTime::format($this->clock->now())]);

        $summary = $this->renewals()->tick();

        self::assertSame(0, $summary['renewals_paid'] + $summary['renewals_failed']);
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS c FROM invoices')[0]['c']);
    }

    public function testForcingARenewalIgnoresTheScheduleAndTheCancellation(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->payWithFake($workspace, $owner, 'pro');
        $this->subscriptionService()->cancelRenewal($workspace->id, $owner->id);
        $before = $this->subscription($workspace);

        $result = $this->renewals()->renew($before, true);

        self::assertSame('paid', $result);
        self::assertSame($before->currentPeriodEnd?->modify('+1 month')->format('Y-m-d H:i'), $this->subscription($workspace)->currentPeriodEnd?->format('Y-m-d H:i'));
    }

    public function testABookedDowngradeIsChargedAndAppliedAtTheRenewal(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->payWithFake($workspace, $owner, 'pro');
        $start = $this->plans()->findByCode('start') ?? self::fail('start');
        $this->subscriptionService()->scheduleChange($workspace->id, $start, BillingPeriod::Month, $owner->id);
        $this->setClockTo($this->subscription($workspace)->currentPeriodEnd?->modify('-3 days'));

        $this->renewals()->tick();

        $s = $this->subscription($workspace);
        self::assertSame($start->id, $s->planId);
        self::assertNull($s->pendingPlanId);
        self::assertSame(39000, $s->priceAmount);
        $renewal = $this->db->select("SELECT amount, plan_id, kind FROM invoices WHERE kind = 'renewal'")[0];
        self::assertSame(39000, (int) $renewal['amount'], 'the new, lower price is charged');
        self::assertSame($start->id, (int) $renewal['plan_id']);
    }

    public function testTheTrialIsRemindedOnceThenEndsInFree(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        foreach ([1, 2, 3] as $i) {
            $this->fakeChannel($workspace, $owner, 'fake-' . $i, 'Канал ' . $i);
            $this->clock->advance(5);
        }
        $ends = $this->subscription($workspace)->trialEndsAt ?? self::fail('trial end');

        $this->setClockTo($ends->modify('-4 days'));
        self::assertSame(0, $this->renewals()->tick()['trial_reminders']);

        $this->setClockTo($ends->modify('-2 days'));
        self::assertSame(1, $this->renewals()->tick()['trial_reminders']);
        self::assertSame(0, $this->renewals()->tick()['trial_reminders'], 'only one reminder');
        self::assertSame(1, count(array_filter($this->subjects(), static fn (string $s): bool => $s === 'Пробный период заканчивается')));

        $this->setClockTo($ends);
        self::assertSame(1, $this->renewals()->tick()['dropped_to_free']);
        $s = $this->subscription($workspace);
        self::assertSame('free', $this->plans()->find($s->planId)?->code);
        self::assertNull($s->trialEndsAt);
        $statuses = array_map(static fn ($c): string => $c->status->value, $this->app->container()->get(ChannelRepository::class)->all($this->contextFor($workspace, $owner)));
        self::assertSame(1, count(array_filter($statuses, static fn (string $s): bool => $s === 'paused')), 'the third channel is paused, none deleted');
        self::assertContains('Пробный период закончился', $this->subjects());
    }

    public function testAPaidWorkspaceDoesNotGetTrialMail(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->payWithFake($workspace, $owner, 'pro');
        $this->setClockTo($this->clock->now()->modify('+12 days'));

        $summary = $this->renewals()->tick();

        self::assertSame(0, $summary['trial_reminders'] + $summary['dropped_to_free']);
    }

    public function testALostNotificationIsRecoveredByAskingTheProviderAfterAFewMinutes(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->givePlan($workspace, 'free');
        $result = $this->billing()->checkout($this->contextFor($workspace, $owner), $owner, 'pro', BillingPeriod::Month, 'fake', true);
        $payment = $this->paymentsRepo()->findByPublicId((string) $result->paymentId) ?? self::fail('payment');
        $this->app->container()->get(FakeGateway::class)->answer($payment->publicId, 'pay');

        $this->clock->advance(120);
        self::assertSame(0, $this->renewals()->tick()['payments_checked'], 'a fresh payment is left alone');
        self::assertSame('free', $this->plans()->find($this->subscription($workspace)->planId)?->code);

        $this->clock->advance(600);
        self::assertSame(1, $this->renewals()->tick()['payments_checked']);
        self::assertSame('pro', $this->plans()->find($this->subscription($workspace)->planId)?->code);
    }

    public function testUnpaidInvoicesExpire(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->givePlan($workspace, 'free');
        $result = $this->billing()->checkout($this->contextFor($workspace, $owner), $owner, 'pro', BillingPeriod::Month, 'fake', true);
        $payment = $this->paymentsRepo()->findByPublicId((string) $result->paymentId) ?? self::fail('payment');

        $this->clock->advance(25 * 3600);
        $this->renewals()->tick();

        self::assertSame(InvoiceStatus::Void, $this->invoices()->findById($payment->invoiceId)?->status);
    }

    public function testAProviderOutageDuringARenewalIsNotTheCustomersFaultAndIsRetriedInAnHour(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->payWithFake($workspace, $owner, 'pro');
        $subscription = $this->subscription($workspace);
        $method = $this->app->container()->get(PaymentMethodRepository::class)->remember($workspace->id, 'yookassa', 'saved-method', 'Visa •• 4242', null);
        $this->subscriptions()->update($subscription->id, ['payment_method_id' => $method->id]);
        $this->http->expect('POST', BillingFixtures::YOOKASSA_API . '/payments', 503, '');

        $result = $this->renewals()->renew($this->subscription($workspace), true);

        self::assertSame('waiting', $result);
        $s = $this->subscription($workspace);
        self::assertSame(0, $s->renewalAttempts, 'an outage is not a failed attempt');
        self::assertSame($this->clock->now()->modify('+1 hour')->format('Y-m-d H:i'), $s->nextRenewalAttemptAt?->format('Y-m-d H:i'));
        self::assertSame([], array_filter($this->subjects(), static fn (string $s): bool => str_starts_with($s, 'Не удалось')), 'no scary email for an outage');
    }

    public function testRenewalThroughYooKassaUsesTheSavedMethodAndVerifiesTheAmount(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->payWithFake($workspace, $owner, 'pro');
        $subscription = $this->subscription($workspace);
        $method = $this->app->container()->get(PaymentMethodRepository::class)->remember($workspace->id, 'yookassa', 'saved-method', 'Visa •• 4242', null);
        $this->subscriptions()->update($subscription->id, ['payment_method_id' => $method->id]);
        $this->http->expect('POST', BillingFixtures::YOOKASSA_API . '/payments', 200, BillingFixtures::yookassaRaw('payment_succeeded'));

        $result = $this->renewals()->renew($this->subscription($workspace), true);

        self::assertSame('paid', $result);
        $call = $this->http->requests[0]['options']['json'];
        self::assertSame('saved-method', $call['payment_method_id']);
        self::assertSame('990.00', $call['amount']['value']);
        self::assertSame(InvoiceKind::Renewal->value, (string) $this->db->select('SELECT kind FROM invoices ORDER BY id DESC LIMIT 1')[0]['kind']);
    }

    public function testAnAmountThatDoesNotMatchTheInvoiceIsNotApplied(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->payWithFake($workspace, $owner, 'pro');
        $subscription = $this->subscription($workspace);
        $method = $this->app->container()->get(PaymentMethodRepository::class)->remember($workspace->id, 'yookassa', 'saved-method', 'Visa •• 4242', null);
        $this->subscriptions()->update($subscription->id, ['payment_method_id' => $method->id]);
        $this->http->expect('POST', BillingFixtures::YOOKASSA_API . '/payments', 200, BillingFixtures::yookassaRaw('payment_succeeded', ['amount' => ['value' => '1.00', 'currency' => 'RUB']]));
        $endBefore = $subscription->currentPeriodEnd;

        $this->renewals()->renew($this->subscription($workspace), true);

        self::assertEquals($endBefore, $this->subscription($workspace)->currentPeriodEnd, 'the period is not extended for the wrong amount');
        self::assertContains('billing.amount_mismatch', $this->auditActions($workspace));
    }

    public function testOneBrokenSubscriptionDoesNotStopTheOthers(): void
    {
        [$ownerA, $a] = $this->ownerWithWorkspace('a@example.com', 'А');
        [$ownerB, $b] = $this->ownerWithWorkspace('b@example.com', 'Б');
        $this->payWithFake($a, $ownerA, 'pro');
        $this->payWithFake($b, $ownerB, 'pro');
        // A's saved method points at a provider whose call blows up (no expectation registered on the mock client).
        $method = $this->app->container()->get(PaymentMethodRepository::class)->remember($a->id, 'yookassa', 'broken', 'Visa', null);
        $this->subscriptions()->update($this->subscription($a)->id, ['payment_method_id' => $method->id]);
        $due = DbTime::format($this->clock->now()->modify('-1 minute'));
        $this->subscriptions()->update($this->subscription($a)->id, ['next_renewal_attempt_at' => $due]);
        $this->subscriptions()->update($this->subscription($b)->id, ['next_renewal_attempt_at' => $due]);

        $summary = $this->renewals()->tick();

        self::assertSame(1, $summary['renewals_paid'], 'B was renewed although A blew up');
        self::assertNotNull($this->subscription($a)->nextRenewalAttemptAt);
        self::assertGreaterThan($this->clock->now(), $this->subscription($a)->nextRenewalAttemptAt, 'A is not hammered every minute');
    }
}
