<?php

declare(strict_types=1);

namespace App\Tests\Integration\Billing;

use App\Domain\Billing\BillingException;
use App\Domain\Billing\BillingPeriod;
use App\Domain\Billing\BillingService;
use App\Domain\Billing\InvoiceKind;
use App\Domain\Billing\InvoiceStatus;
use App\Domain\Billing\Ledger;
use App\Domain\Billing\PaymentOutcome;
use App\Domain\Billing\PaymentStatus;
use App\Domain\Billing\SubscriptionStatus;
use App\Domain\Workspace\Role;
use App\Integrations\Payments\Fake\FakeGateway;
use App\Tests\Support\BillingTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The checkout and the application of a payment, through the test provider (the same code path real providers use).
 */
#[CoversClass(BillingService::class)]
final class BillingServiceTest extends BillingTestCase
{
    public function testPayingProAppliesThePlanThePeriodTheCardAndTheNextCharge(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->givePlan($workspace, 'free');

        [$invoice, $payment] = $this->payWithFake($workspace, $owner, 'pro');

        self::assertSame(InvoiceStatus::Paid, $invoice->status);
        self::assertSame(InvoiceKind::New, $invoice->kind);
        self::assertSame(99000, $invoice->amount);
        self::assertSame(PaymentStatus::Succeeded, $payment->status);
        $s = $this->subscription($workspace);
        self::assertSame('pro', $this->plans()->find($s->planId)?->code);
        self::assertSame(SubscriptionStatus::Active, $s->status);
        self::assertSame(BillingPeriod::Month, $s->period);
        self::assertSame(99000, $s->priceAmount);
        self::assertSame($this->clock->now()->modify('+1 month')->format('Y-m-d H:i'), $s->currentPeriodEnd?->format('Y-m-d H:i'));
        self::assertNull($s->trialEndsAt);
        self::assertFalse($s->cancelAtPeriodEnd);
        self::assertNotNull($s->paymentMethodId, 'the card is kept for renewals');
        self::assertSame($s->currentPeriodEnd->modify('-3 days')->format('Y-m-d H:i'), $s->nextRenewalAttemptAt?->format('Y-m-d H:i'), 'the first renewal attempt is three days before the end');
        self::assertSame('pro', $this->plans()->find((int) $this->db->select('SELECT plan_id FROM workspaces WHERE id = ?', [$workspace->id])[0]['plan_id'])?->code);
        self::assertContains('billing.payment_succeeded', $this->auditActions($workspace));
    }

    public function testThePaymentIsRecordedInTheLedgerAndTheOwnerGetsAnEmail(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();

        [$invoice] = $this->payWithFake($workspace, $owner, 'start');

        $ledger = $this->app->container()->get(Ledger::class);
        self::assertSame(39000, $ledger->balance('provider:fake'));
        self::assertSame(-39000, $ledger->balance(Ledger::REVENUE));
        $this->drainQueue();
        $mails = $this->mailer->to((string) $owner->email);
        self::assertCount(1, $mails);
        self::assertSame('Оплата получена: тариф «Старт»', $mails[0]->subject);
        self::assertStringContainsString($invoice->number, $mails[0]->text);
    }

    public function testNotKeepingTheCardMeansTheCustomerRenewsByHand(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();

        $this->payWithFake($workspace, $owner, 'pro', BillingPeriod::Month, keepCard: false);

        $s = $this->subscription($workspace);
        self::assertTrue($s->cancelAtPeriodEnd);
        self::assertNull($s->paymentMethodId);
        self::assertNull($s->nextRenewalAttemptAt, 'nothing is charged by itself');
        self::assertFalse($s->autoRenews());
    }

    public function testAYearlyPaymentGivesAYear(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();

        [$invoice] = $this->payWithFake($workspace, $owner, 'pro', BillingPeriod::Year);

        self::assertSame(990000, $invoice->amount);
        self::assertSame($this->clock->now()->modify('+1 year')->format('Y-m-d'), $this->subscription($workspace)->currentPeriodEnd?->format('Y-m-d'));
    }

    public function testAnUpgradeInTheMiddleOfAPeriodChargesTheProratedDifferenceAndKeepsTheEnd(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->givePlan($workspace, 'free');
        $this->payWithFake($workspace, $owner, 'start');
        $before = $this->subscription($workspace);
        // Halfway through the 28-day... period: the clock moves to the exact middle.
        $start = $before->currentPeriodStart ?? self::fail('start');
        $end = $before->currentPeriodEnd ?? self::fail('end');
        $this->clock->set(gmdate('Y-m-d H:i:s', $start->getTimestamp() + intdiv($end->getTimestamp() - $start->getTimestamp(), 2)));

        [$invoice] = $this->payWithFake($workspace, $owner, 'pro');

        self::assertSame(InvoiceKind::Upgrade, $invoice->kind);
        self::assertSame((int) round((99000 - 39000) / 2), $invoice->amount, 'half of the price difference');
        self::assertSame(99000, $invoice->listPrice);
        $after = $this->subscription($workspace);
        self::assertSame('pro', $this->plans()->find($after->planId)?->code, 'the better plan works at once');
        self::assertEquals($before->currentPeriodEnd, $after->currentPeriodEnd, 'the paid period does not move');
        self::assertEquals($before->currentPeriodStart, $after->currentPeriodStart);
        self::assertSame(99000, $after->priceAmount, 'the next upgrade is credited from the new price');
    }

    public function testADowngradeCostsNothingNowAndIsBookedForTheEnd(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->payWithFake($workspace, $owner, 'pro');
        $invoicesBefore = (int) $this->db->select('SELECT COUNT(*) AS c FROM invoices')[0]['c'];
        $context = $this->contextFor($workspace, $owner);

        $result = $this->billing()->checkout($context, $owner, 'start', BillingPeriod::Month, 'fake', true);

        self::assertNull($result->paymentId);
        self::assertStringContainsString('когда закончится оплаченный срок', (string) $result->message);
        self::assertSame($invoicesBefore, (int) $this->db->select('SELECT COUNT(*) AS c FROM invoices')[0]['c'], 'no bill for a downgrade');
        $s = $this->subscription($workspace);
        self::assertSame('pro', $this->plans()->find($s->planId)?->code);
        self::assertSame('start', $this->plans()->find((int) $s->pendingPlanId)?->code);
    }

    public function testAFailedPaymentLeavesTheInvoiceOpenAndChangesNothing(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->givePlan($workspace, 'free');

        [$invoice, $payment] = $this->payWithFake($workspace, $owner, 'pro', answer: 'decline');

        self::assertSame(PaymentStatus::Failed, $payment->status);
        self::assertSame(InvoiceStatus::Open, $invoice->status);
        self::assertSame('free', $this->plans()->find($this->subscription($workspace)->planId)?->code);
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM ledger_entries')[0]['c']);
        self::assertContains('billing.payment_failed', $this->auditActions($workspace));
    }

    public function testApplyingTheSamePaymentTwiceChangesNothingTheSecondTime(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        [, $payment] = $this->payWithFake($workspace, $owner, 'pro');
        $period = $this->subscription($workspace)->currentPeriodEnd;

        $again = $this->billing()->sync($payment);

        self::assertSame(PaymentOutcome::AlreadySettled, $again);
        self::assertEquals($period, $this->subscription($workspace)->currentPeriodEnd, 'the period is not extended twice');
        self::assertSame(2, (int) $this->db->select('SELECT COUNT(*) AS c FROM ledger_entries')[0]['c'], 'one transaction, two entries');
        $this->drainQueue();
        self::assertCount(1, $this->mailer->to((string) $owner->email), 'one receipt email, not two');
    }

    public function testANewChoiceReplacesTheUnpaidInvoiceAndALatePaymentForTheOldOneIsRecordedButNotApplied(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->givePlan($workspace, 'free');
        $context = $this->contextFor($workspace, $owner);
        $first = $this->billing()->checkout($context, $owner, 'pro', BillingPeriod::Month, 'fake', true);
        $second = $this->billing()->checkout($context, $owner, 'start', BillingPeriod::Month, 'fake', true);
        $firstPayment = $this->paymentsRepo()->findByPublicId((string) $first->paymentId) ?? self::fail('first');
        $secondPayment = $this->paymentsRepo()->findByPublicId((string) $second->paymentId) ?? self::fail('second');
        self::assertSame(InvoiceStatus::Void, $this->invoices()->findById($firstPayment->invoiceId)?->status);
        $fake = $this->app->container()->get(FakeGateway::class);

        // The customer pays the page they had left open: money arrives for a cancelled invoice.
        $fake->answer($firstPayment->publicId, 'pay');
        $orphan = $this->billing()->sync($firstPayment);

        self::assertSame(PaymentOutcome::Orphaned, $orphan);
        self::assertSame('free', $this->plans()->find($this->subscription($workspace)->planId)?->code, 'a cancelled bill must not change the plan');
        self::assertSame(99000, $this->app->container()->get(Ledger::class)->balance('provider:fake'), 'but the money is on the books');
        self::assertContains('billing.payment_orphaned', $this->auditActions($workspace));

        // The current invoice still works.
        $fake->answer($secondPayment->publicId, 'pay');
        self::assertSame(PaymentOutcome::Settled, $this->billing()->sync($secondPayment));
        self::assertSame('start', $this->plans()->find($this->subscription($workspace)->planId)?->code);
    }

    public function testOnlyTheOwnerMayPay(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $admin = $this->memberOf($workspace, 'admin@example.com', Role::Admin);

        $this->expectException(BillingException::class);
        $this->expectExceptionMessage('владелец');
        $this->billing()->checkout($this->contextFor($workspace, $admin), $admin, 'pro', BillingPeriod::Month, 'fake', true);
    }

    public function testAnUnknownOrSwitchedOffProviderIsRefusedBeforeAnyInvoiceExists(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->givePlan($workspace, 'free');

        try {
            $this->billing()->checkout($this->contextFor($workspace, $owner), $owner, 'pro', BillingPeriod::Month, 'stripe', true);
            self::fail('must be refused');
        } catch (BillingException $e) {
            self::assertStringContainsString('способ оплаты', $e->getMessage());
        }
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM invoices')[0]['c']);
    }

    public function testAnUnknownPlanAndTheFreePlanCannotBePurchased(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $context = $this->contextFor($workspace, $owner);

        foreach (['nope', 'free'] as $code) {
            try {
                $this->billing()->checkout($context, $owner, $code, BillingPeriod::Month, 'fake', true);
                self::fail($code . ' must be refused');
            } catch (BillingException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testACustomerWithoutAnEmailIsAskedToAddOne(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $noEmail = new \App\Domain\User\User($owner->id, null, null, null, $owner->name, $owner->locale, $owner->timezone, null, null, false, $owner->status, $owner->createdAt);

        $this->expectException(BillingException::class);
        $this->expectExceptionMessage('почту');
        $this->billing()->checkout($this->contextFor($workspace, $owner), $noEmail, 'pro', BillingPeriod::Month, 'fake', true);
    }

    public function testRefundsMoveMoneyBackAndMarkThePayment(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        [, $payment] = $this->payWithFake($workspace, $owner, 'pro');

        $this->billing()->refund($payment, 30000, $owner->id);
        $partial = $this->paymentsRepo()->findById($payment->id) ?? self::fail('payment');
        self::assertSame(30000, $partial->refundedAmount);
        self::assertSame(PaymentStatus::Succeeded, $partial->status);

        $this->billing()->refund($partial, 69000, $owner->id);
        $full = $this->paymentsRepo()->findById($payment->id) ?? self::fail('payment');

        self::assertSame(PaymentStatus::Refunded, $full->status);
        self::assertSame(0, $this->app->container()->get(Ledger::class)->balance('provider:fake'));
        $this->expectException(BillingException::class);
        $this->billing()->refund($full, 100, $owner->id);
    }

    public function testRefundsBeyondWhatWasPaidAreRefused(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        [, $payment] = $this->payWithFake($workspace, $owner, 'pro');

        foreach ([0, -5, 99001] as $amount) {
            try {
                $this->billing()->refund($payment, $amount, $owner->id);
                self::fail('amount ' . $amount . ' must be refused');
            } catch (BillingException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testRemovingTheCardStopsAutomaticCharges(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->payWithFake($workspace, $owner, 'pro');

        $this->billing()->forgetMethod($this->contextFor($workspace, $owner), $owner->id);

        $s = $this->subscription($workspace);
        self::assertNull($s->paymentMethodId);
        self::assertNull($s->nextRenewalAttemptAt);
        self::assertSame('revoked', (string) $this->db->select('SELECT status FROM payment_methods')[0]['status']);
    }
}
