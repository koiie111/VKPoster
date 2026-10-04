<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\Billing\Invoice;
use App\Domain\Billing\InvoiceRepository;
use App\Domain\Billing\Payment;
use App\Domain\Billing\PaymentRepository;
use App\Domain\Billing\PlanRepository;
use App\Domain\Billing\Subscription;
use App\Domain\Billing\SubscriptionRepository;
use App\Domain\Billing\BillingPeriod;
use App\Domain\Billing\BillingService;
use App\Domain\Billing\RenewalService;
use App\Domain\Billing\SubscriptionService;
use App\Domain\User\User;
use App\Domain\Workspace\Workspace;
use App\Support\DbTime;

/**
 * Base class for billing tests: a workspace owner who can sign in, the services of the stage, and shortcuts to put a workspace on a paid plan
 * the way a real payment would (through the test provider and the same code path).
 */
abstract class BillingTestCase extends PostTestCase
{
    /**
     * The visible text of a page with non-breaking spaces turned into plain ones (amounts are written with them), for assertions.
     */
    protected function plain(\App\Kernel\Http\Response $page): string
    {
        return str_replace("\u{00A0}", ' ', $this->text($page));
    }

    /**
     * The first capture group of a pattern in a text (fails the test when there is no match).
     */
    protected static function capture(string $pattern, string $subject): string
    {
        return preg_match($pattern, $subject, $m) === 1 ? $m[1] : self::fail('No match for ' . $pattern);
    }

    protected function billing(): BillingService
    {
        return $this->app->container()->get(BillingService::class);
    }

    protected function renewals(): RenewalService
    {
        return $this->app->container()->get(RenewalService::class);
    }

    protected function subscriptions(): SubscriptionRepository
    {
        return $this->app->container()->get(SubscriptionRepository::class);
    }

    protected function subscriptionService(): SubscriptionService
    {
        return $this->app->container()->get(SubscriptionService::class);
    }

    protected function invoices(): InvoiceRepository
    {
        return $this->app->container()->get(InvoiceRepository::class);
    }

    protected function paymentsRepo(): PaymentRepository
    {
        return $this->app->container()->get(PaymentRepository::class);
    }

    protected function plans(): PlanRepository
    {
        return $this->app->container()->get(PlanRepository::class);
    }

    protected function subscription(Workspace $workspace): Subscription
    {
        return $this->subscriptions()->findByWorkspace($workspace->id) ?? self::fail('The workspace has no subscription.');
    }

    /**
     * Pay for a plan through the test provider, completing the whole flow (checkout, the payment page, the status check).
     *
     * @return array{Invoice, Payment}
     */
    protected function payWithFake(Workspace $workspace, User $owner, string $plan = 'pro', BillingPeriod $period = BillingPeriod::Month, bool $keepCard = true, string $answer = 'pay'): array
    {
        $context = $this->contextFor($workspace, $owner);
        $result = $this->billing()->checkout($context, $owner, $plan, $period, 'fake', $keepCard);
        self::assertNotNull($result->paymentId);
        $payment = $this->paymentsRepo()->findByPublicId($result->paymentId) ?? self::fail('No payment.');
        $this->app->container()->get(\App\Integrations\Payments\Fake\FakeGateway::class)->answer($payment->publicId, $answer);
        $this->billing()->sync($payment);
        $payment = $this->paymentsRepo()->findById($payment->id) ?? self::fail('No payment.');
        $invoice = $this->invoices()->findById($payment->invoiceId) ?? self::fail('No invoice.');

        return [$invoice, $payment];
    }

    /**
     * Move the paid period of a workspace so that its end is `$modifier` from now (to test renewals without waiting).
     */
    protected function periodEndsIn(Workspace $workspace, string $modifier): void
    {
        $subscription = $this->subscription($workspace);
        $end = $this->clock->now()->modify($modifier);
        $start = $subscription->period === BillingPeriod::Year ? $end->modify('-1 year') : $end->modify('-1 month');
        $this->subscriptions()->update($subscription->id, [
            'current_period_start' => DbTime::format($start),
            'current_period_end' => DbTime::format($end),
            'next_renewal_attempt_at' => DbTime::format($end->modify('-3 days')),
        ]);
    }

    /**
     * The emails queued so far, as subjects (the mail queue is drained, so the tests see what a person would receive).
     *
     * @return list<string>
     */
    protected function mailSubjects(): array
    {
        $this->drainQueue();

        return array_map(static fn ($m): string => $m->subject, $this->mailer->sent);
    }
}
