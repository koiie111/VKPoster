<?php

declare(strict_types=1);

namespace App\Tests\Feature\Billing;

use App\Domain\Billing\BillingPeriod;
use App\Domain\Billing\BillingService;
use App\Domain\Billing\InvoiceStatus;
use App\Domain\Billing\Ledger;
use App\Domain\Billing\PaymentStatus;
use App\Domain\User\User;
use App\Domain\Workspace\Workspace;
use App\Kernel\Http\Response;
use App\Tests\Support\BillingFixtures;
use App\Tests\Support\BillingTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * What the payment providers' notifications may and may not do: authenticity, idempotence, the amount check, outages, races.
 */
#[CoversClass(BillingService::class)]
final class PaymentWebhookTest extends BillingTestCase
{
    private User $owner;
    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->owner, $this->workspace] = $this->ownerWithWorkspace();
        $this->givePlan($this->workspace, 'free');
    }

    private function startYooKassa(): void
    {
        $this->http->expect('POST', BillingFixtures::YOOKASSA_API . '/payments', 200, BillingFixtures::yookassaRaw('payment_pending'));
        $this->billing()->checkout($this->contextFor($this->workspace, $this->owner), $this->owner, 'pro', BillingPeriod::Month, 'yookassa', true);
    }

    private function startTBank(): void
    {
        $this->http->expect('POST', BillingFixtures::TBANK_API . '/Init', 200, BillingFixtures::tbankRaw('init_ok'));
        $this->billing()->checkout($this->contextFor($this->workspace, $this->owner), $this->owner, 'pro', BillingPeriod::Month, 'tbank', true);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function yookassaHook(array $body, string $ip = BillingFixtures::YOOKASSA_IP): Response
    {
        $this->remoteAddr = $ip;

        return $this->request('POST', '/webhooks/billing/yookassa', $body, ['Content-Type' => 'application/json']);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function tbankHook(array $body): Response
    {
        $this->remoteAddr = '203.0.113.77';

        return $this->request('POST', '/webhooks/billing/tbank', $body, ['Content-Type' => 'application/json']);
    }

    private function planCode(): string
    {
        return $this->plans()->find($this->subscription($this->workspace)->planId)->code ?? '';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function events(): array
    {
        return $this->db->select('SELECT event_id, outcome, detail FROM webhook_events ORDER BY id');
    }

    public function testANotificationFromAnUnknownAddressIsRefusedAndNothingIsAskedOrChanged(): void
    {
        $this->startYooKassa();
        $calls = count($this->http->requests);

        $response = $this->yookassaHook(BillingFixtures::yookassa('webhook_payment_succeeded'), '203.0.113.99');

        self::assertSame(403, $response->status);
        self::assertSame($calls, count($this->http->requests), 'a forged call does not even make us ask the provider');
        self::assertSame('free', $this->planCode());
        self::assertSame([], $this->events());
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM ledger_entries')[0]['c']);
    }

    public function testAGenuineNotificationSettlesThePaymentAfterTheStatusIsConfirmedWithTheProvider(): void
    {
        $this->startYooKassa();
        $this->http->expect('GET', BillingFixtures::YOOKASSA_API . '/payments/' . BillingFixtures::YOOKASSA_PAYMENT, 200, BillingFixtures::yookassaRaw('payment_succeeded'));

        $response = $this->yookassaHook(BillingFixtures::yookassa('webhook_payment_succeeded'));

        self::assertSame(200, $response->status);
        self::assertSame('pro', $this->planCode());
        $s = $this->subscription($this->workspace);
        self::assertTrue($s->autoRenews());
        self::assertSame('MasterCard •• 4477', (string) $this->db->select('SELECT title FROM payment_methods')[0]['title']);
        self::assertSame(99000, $this->app->container()->get(Ledger::class)->balance('provider:yookassa'));
        self::assertSame([['event_id' => 'payment.succeeded:' . BillingFixtures::YOOKASSA_PAYMENT, 'outcome' => 'processed', 'detail' => 'settled']], $this->events());
        $this->drainQueue();
        self::assertCount(1, $this->mailer->to((string) $this->owner->email));
        self::assertSame(1, (int) $this->db->select("SELECT COUNT(*) AS c FROM invoices WHERE status = 'paid'")[0]['c']);
    }

    public function testTheSameNotificationDeliveredTwiceIsProcessedOnce(): void
    {
        $this->startYooKassa();
        $this->http->expect('GET', BillingFixtures::YOOKASSA_API . '/payments/' . BillingFixtures::YOOKASSA_PAYMENT, 200, BillingFixtures::yookassaRaw('payment_succeeded'));
        $body = BillingFixtures::yookassa('webhook_payment_succeeded');
        $this->yookassaHook($body);
        $calls = count($this->http->requests);
        $end = $this->subscription($this->workspace)->currentPeriodEnd;

        $again = $this->yookassaHook($body);

        self::assertSame(200, $again->status, 'the provider is told it was received, so it stops retrying');
        self::assertSame($calls, count($this->http->requests), 'a repeat is not even looked at');
        self::assertEquals($end, $this->subscription($this->workspace)->currentPeriodEnd);
        self::assertCount(1, $this->events());
        self::assertSame(2, (int) $this->db->select('SELECT COUNT(*) AS c FROM ledger_entries')[0]['c']);
    }

    public function testTwoDifferentNotificationsAboutOnePaymentDoNotApplyItTwice(): void
    {
        $this->startYooKassa();
        $this->http->expect('GET', BillingFixtures::YOOKASSA_API . '/payments/' . BillingFixtures::YOOKASSA_PAYMENT, 200, BillingFixtures::yookassaRaw('payment_succeeded'));
        $this->http->expect('GET', BillingFixtures::YOOKASSA_API . '/payments/' . BillingFixtures::YOOKASSA_PAYMENT, 200, BillingFixtures::yookassaRaw('payment_succeeded'));
        $first = BillingFixtures::yookassa('webhook_payment_succeeded');
        $second = $first;
        $second['event'] = 'payment.waiting_for_capture';

        $this->yookassaHook($first);
        $end = $this->subscription($this->workspace)->currentPeriodEnd;
        $this->yookassaHook($second);

        self::assertEquals($end, $this->subscription($this->workspace)->currentPeriodEnd, 'the period is extended once');
        self::assertCount(2, $this->events(), 'both deliveries are on record');
        self::assertSame('already_settled', $this->events()[1]['detail']);
        self::assertSame(2, (int) $this->db->select('SELECT COUNT(*) AS c FROM ledger_entries')[0]['c']);
        $this->drainQueue();
        self::assertCount(1, $this->mailer->to((string) $this->owner->email));
    }

    public function testAnAmountInTheNotificationThatDiffersFromTheInvoiceIsRefusedWithAnAlert(): void
    {
        $this->startYooKassa();
        $calls = count($this->http->requests);
        $body = BillingFixtures::yookassa('webhook_payment_succeeded');
        $body['object']['amount']['value'] = '1.00';

        $response = $this->yookassaHook($body);

        self::assertSame(200, $response->status);
        self::assertSame($calls, count($this->http->requests));
        self::assertSame('free', $this->planCode());
        self::assertSame('rejected', $this->events()[0]['outcome']);
        self::assertContains('billing.amount_mismatch', $this->auditActions($this->workspace));
        self::assertSame(InvoiceStatus::Open->value, (string) $this->db->select('SELECT status FROM invoices')[0]['status']);
    }

    public function testAnAmountThatTheProviderItselfReportsDifferentlyIsRefusedToo(): void
    {
        $this->startYooKassa();
        $this->http->expect('GET', BillingFixtures::YOOKASSA_API . '/payments/' . BillingFixtures::YOOKASSA_PAYMENT, 200, BillingFixtures::yookassaRaw('payment_succeeded', ['amount' => ['value' => '10.00', 'currency' => 'RUB']]));

        $this->yookassaHook(BillingFixtures::yookassa('webhook_payment_succeeded'));

        self::assertSame('free', $this->planCode());
        self::assertSame('rejected', $this->events()[0]['outcome']);
        self::assertContains('billing.amount_mismatch', $this->auditActions($this->workspace));
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM ledger_entries')[0]['c'], 'wrong money is not booked as a payment');
    }

    public function testACurrencyThatDiffersIsRefusedLikeAnAmount(): void
    {
        $this->startYooKassa();
        $this->http->expect('GET', BillingFixtures::YOOKASSA_API . '/payments/' . BillingFixtures::YOOKASSA_PAYMENT, 200, BillingFixtures::yookassaRaw('payment_succeeded', ['amount' => ['value' => '990.00', 'currency' => 'USD']]));

        $this->yookassaHook(BillingFixtures::yookassa('webhook_payment_succeeded'));

        self::assertSame('free', $this->planCode());
    }

    public function testANotificationAboutAPaymentWeDoNotKnowIsAcknowledgedAndIgnored(): void
    {
        $body = BillingFixtures::yookassa('webhook_payment_succeeded');
        $body['object']['id'] = 'some-other-merchants-payment';

        $response = $this->yookassaHook($body);

        self::assertSame(200, $response->status);
        self::assertSame('ignored', $this->events()[0]['outcome']);
        self::assertSame([], $this->http->requests);
    }

    public function testWhenTheProviderCannotBeAskedWeAnswerWithAnErrorSoItRepeatsTheNotification(): void
    {
        $this->startYooKassa();
        $body = BillingFixtures::yookassa('webhook_payment_succeeded');
        $this->http->expect('GET', BillingFixtures::YOOKASSA_API . '/payments/' . BillingFixtures::YOOKASSA_PAYMENT, 503, '');

        $down = $this->yookassaHook($body);

        self::assertSame(503, $down->status);
        self::assertSame([], $this->events(), 'the failed delivery is forgotten, so the retry is not taken for a repeat');
        self::assertSame('free', $this->planCode());

        $this->http->expect('GET', BillingFixtures::YOOKASSA_API . '/payments/' . BillingFixtures::YOOKASSA_PAYMENT, 200, BillingFixtures::yookassaRaw('payment_succeeded'));
        $retry = $this->yookassaHook($body);

        self::assertSame(200, $retry->status);
        self::assertSame('pro', $this->planCode());
    }

    public function testACanceledPaymentKeepsTheInvoiceOpenAndMarksThePaymentFailed(): void
    {
        $this->startYooKassa();
        $this->http->expect('GET', BillingFixtures::YOOKASSA_API . '/payments/' . BillingFixtures::YOOKASSA_PAYMENT, 200, BillingFixtures::yookassaRaw('payment_canceled', ['id' => BillingFixtures::YOOKASSA_PAYMENT]));
        $body = BillingFixtures::yookassa('webhook_payment_succeeded');
        $body['event'] = 'payment.canceled';

        $this->yookassaHook($body);

        self::assertSame('free', $this->planCode());
        $payment = $this->paymentsRepo()->findByProviderId('yookassa', BillingFixtures::YOOKASSA_PAYMENT) ?? self::fail('payment');
        self::assertSame(PaymentStatus::Failed, $payment->status);
        self::assertSame(InvoiceStatus::Open, $this->invoices()->findById($payment->invoiceId)?->status);
    }

    public function testUnknownProvidersAndTheTestProviderTakeNoNotifications(): void
    {
        self::assertSame(404, $this->request('POST', '/webhooks/billing/stripe', ['a' => 1], ['Content-Type' => 'application/json'])->status);
        self::assertSame(403, $this->request('POST', '/webhooks/billing/fake', ['a' => 1], ['Content-Type' => 'application/json'])->status);
        self::assertSame(405, $this->get('/webhooks/billing/yookassa')->status, 'only POST is accepted');
    }

    public function testATBankNotificationWithAValidTokenSettlesAndKeepsTheRebillId(): void
    {
        $this->startTBank();
        $this->http->expect('POST', BillingFixtures::TBANK_API . '/GetState', 200, BillingFixtures::tbankRaw('getstate_confirmed'));

        $response = $this->tbankHook(BillingFixtures::tbankNotification());

        self::assertSame(200, $response->status);
        self::assertSame('OK', $response->body, 'T-Bank wants the plain text OK');
        self::assertSame('pro', $this->planCode());
        $method = $this->db->select('SELECT provider, provider_method_id, title FROM payment_methods')[0];
        self::assertSame(['provider' => 'tbank', 'provider_method_id' => '1700000000001', 'title' => 'Карта •• 0777'], $method);
        self::assertTrue($this->subscription($this->workspace)->autoRenews());
        self::assertSame(99000, $this->app->container()->get(Ledger::class)->balance('provider:tbank'));
    }

    public function testATBankNotificationWithABadTokenIsRefused(): void
    {
        $this->startTBank();
        $calls = count($this->http->requests);

        $forged = BillingFixtures::tbankNotification();
        $forged['Amount'] = 100;
        $wrongKey = BillingFixtures::tbankNotification([], 'guessed-password');

        self::assertSame(403, $this->tbankHook($forged)->status);
        self::assertSame(403, $this->tbankHook($wrongKey)->status);
        self::assertSame($calls, count($this->http->requests));
        self::assertSame('free', $this->planCode());
        self::assertSame([], $this->events());
    }

    public function testATBankNotificationIsConfirmedWithGetStateEvenWhenItsSignatureIsRight(): void
    {
        $this->startTBank();
        // A valid, signed notification says CONFIRMED, but the bank's own status check says the card was rejected: the status check wins.
        $this->http->expect('POST', BillingFixtures::TBANK_API . '/GetState', 200, BillingFixtures::tbankRaw('getstate_rejected'));

        $this->tbankHook(BillingFixtures::tbankNotification());

        self::assertSame('free', $this->planCode());
        self::assertSame(PaymentStatus::Failed, $this->paymentsRepo()->findByProviderId('tbank', BillingFixtures::TBANK_PAYMENT)?->status);
    }

    public function testATBankAmountThatDiffersIsRefused(): void
    {
        $this->startTBank();

        $this->tbankHook(BillingFixtures::tbankNotification(['Amount' => 1000]));

        self::assertSame('free', $this->planCode());
        self::assertSame('rejected', $this->events()[0]['outcome']);
    }
}
