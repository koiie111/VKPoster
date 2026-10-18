<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payments;

use App\Domain\Billing\PaymentStatus;
use App\Integrations\Payments\Contracts\GatewayException;
use App\Integrations\Payments\Contracts\WebhookRejected;
use App\Integrations\Payments\Stripe\StripeGateway;
use App\Kernel\Http\Request;
use App\Tests\Support\BillingFixtures;
use App\Tests\Support\FakeClock;
use App\Tests\Support\MockHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StripeGateway::class)]
final class StripeGatewayTest extends TestCase
{
    private const API = 'https://stripe.test/v1';
    private const SECRET = 'whsec_test';

    private MockHttpClient $http;
    private FakeClock $clock;

    protected function setUp(): void
    {
        $this->http = new MockHttpClient();
        $this->clock = new FakeClock('2026-10-18 12:00:00');
    }

    private function gateway(): StripeGateway
    {
        return new StripeGateway($this->http, 'sk_test_x', self::SECRET, $this->clock, ['USD', 'EUR'], self::API);
    }

    private function signed(string $body, ?int $timestamp = null, string $secret = self::SECRET): Request
    {
        $timestamp ??= $this->clock->now()->getTimestamp();
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, $secret);

        return Request::create('POST', '/webhooks/billing/stripe', headers: ['Stripe-Signature' => 't=' . $timestamp . ',v1=' . $signature], rawBody: $body);
    }

    private static function event(string $type = 'checkout.session.completed', string $objectId = 'cs_test_1', int $amount = 1999): string
    {
        return json_encode(['id' => 'evt_1', 'type' => $type, 'data' => ['object' => ['id' => $objectId, 'amount_total' => $amount]]], JSON_THROW_ON_ERROR);
    }

    private static function invoice(): \App\Domain\Billing\Invoice
    {
        $i = BillingFixtures::invoice(1999);

        return new \App\Domain\Billing\Invoice($i->id, $i->publicId, $i->number, $i->workspaceId, $i->subscriptionId, $i->planId, $i->period, $i->kind, 1999, 1999, 'USD', $i->status, $i->description, $i->customerEmail, $i->periodStart, $i->periodEnd, $i->createdAt, null, $i->expiresAt);
    }

    public function testItNeedsBothKeysAndTakesDollarsAndEuros(): void
    {
        self::assertTrue($this->gateway()->isConfigured());
        self::assertFalse((new StripeGateway($this->http, 'sk', '', $this->clock))->isConfigured());
        self::assertSame(['USD', 'EUR'], $this->gateway()->currencies());
        self::assertTrue($this->gateway()->supportsRecurring());
    }

    public function testACheckoutSessionIsCreatedInCentsAndAsksToKeepTheCard(): void
    {
        $this->http->expect('POST', self::API . '/checkout/sessions', 200, json_encode(['id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1', 'payment_status' => 'unpaid', 'status' => 'open', 'amount_total' => 1999, 'currency' => 'usd'], JSON_THROW_ON_ERROR));

        $created = $this->gateway()->createPayment(self::invoice(), BillingFixtures::payment('stripe', null, 1999), 'https://app.example.com/back', true);

        self::assertSame('cs_test_1', $created->providerPaymentId);
        self::assertSame(PaymentStatus::Pending, $created->status);
        self::assertSame('https://checkout.stripe.com/c/pay/cs_test_1', $created->confirmationUrl);
        $sent = $this->http->requests[0]['options'];
        parse_str($sent['body'], $form);
        self::assertSame('payment', $form['mode']);
        self::assertSame('1999', $form['line_items'][0]['price_data']['unit_amount']);
        self::assertSame('usd', $form['line_items'][0]['price_data']['currency']);
        self::assertIsArray($form['payment_intent_data']);
        self::assertSame('off_session', $form['payment_intent_data']['setup_future_usage']);
        self::assertSame('Bearer sk_test_x', $sent['headers']['Authorization']);
        self::assertSame('01JTESTPAYMENT000000000000', $sent['headers']['Idempotency-Key']);
    }

    public function testAPaidSessionIsSucceededAndBringsTheSavedCard(): void
    {
        $this->http->expect('GET', self::API . '/checkout/sessions/cs_test_1?expand[]=payment_intent.payment_method', 200, json_encode([
            'id' => 'cs_test_1', 'payment_status' => 'paid', 'status' => 'complete', 'amount_total' => 1999, 'currency' => 'usd', 'customer' => 'cus_9',
            'payment_intent' => ['id' => 'pi_1', 'setup_future_usage' => 'off_session', 'payment_method' => ['id' => 'pm_1', 'card' => ['brand' => 'visa', 'last4' => '4242']]],
        ], JSON_THROW_ON_ERROR));

        $status = $this->gateway()->fetchStatus('cs_test_1');

        self::assertSame(PaymentStatus::Succeeded, $status->status);
        self::assertSame(1999, $status->amount);
        self::assertSame('USD', $status->currency);
        self::assertNotNull($status->method);
        self::assertSame('pm_1', $status->method->providerMethodId);
        self::assertSame('cus_9', $status->method->customerKey);
        self::assertSame('Visa •• 4242', $status->method->title);
    }

    public function testAnExpiredSessionFailsAndAnOpenOneWaits(): void
    {
        $this->http->expect('GET', self::API . '/checkout/sessions/cs_a?expand[]=payment_intent.payment_method', 200, json_encode(['id' => 'cs_a', 'payment_status' => 'unpaid', 'status' => 'expired', 'amount_total' => 100, 'currency' => 'usd'], JSON_THROW_ON_ERROR));
        $this->http->expect('GET', self::API . '/checkout/sessions/cs_b?expand[]=payment_intent.payment_method', 200, json_encode(['id' => 'cs_b', 'payment_status' => 'unpaid', 'status' => 'open', 'amount_total' => 100, 'currency' => 'usd'], JSON_THROW_ON_ERROR));

        self::assertSame(PaymentStatus::Failed, $this->gateway()->fetchStatus('cs_a')->status);
        self::assertSame(PaymentStatus::Pending, $this->gateway()->fetchStatus('cs_b')->status);
    }

    public function testARenewalChargesTheSavedCardOffSession(): void
    {
        $this->http->expect('POST', self::API . '/payment_intents', 200, json_encode(['id' => 'pi_2', 'status' => 'succeeded', 'amount' => 1999, 'currency' => 'usd'], JSON_THROW_ON_ERROR));
        $method = new \App\Domain\Billing\PaymentMethod(1, '01JTESTMETHOD000000000000X', 7, 'stripe', 'pm_1', 'cus_9', 'Visa •• 4242', true, new \DateTimeImmutable('2026-10-01'));

        $charged = $this->gateway()->chargeSaved(self::invoice(), BillingFixtures::payment('stripe', null, 1999), $method);

        self::assertSame(PaymentStatus::Succeeded, $charged->status);
        parse_str($this->http->requests[0]['options']['body'], $form);
        self::assertSame('cus_9', $form['customer']);
        self::assertSame('pm_1', $form['payment_method']);
        self::assertSame('true', $form['off_session']);
    }

    public function testADeclinedRenewalIsAnAnswerNotAnError(): void
    {
        $this->http->expect('POST', self::API . '/payment_intents', 402, json_encode(['error' => ['code' => 'card_declined', 'payment_intent' => ['id' => 'pi_3', 'status' => 'requires_payment_method', 'amount' => 1999, 'currency' => 'usd', 'last_payment_error' => ['code' => 'card_declined']]]], JSON_THROW_ON_ERROR));
        $method = new \App\Domain\Billing\PaymentMethod(1, '01JTESTMETHOD000000000000X', 7, 'stripe', 'pm_1', 'cus_9', 'Visa', true, new \DateTimeImmutable('2026-10-01'));

        $charged = $this->gateway()->chargeSaved(self::invoice(), BillingFixtures::payment('stripe', null, 1999), $method);

        self::assertSame(PaymentStatus::Failed, $charged->status);
        self::assertSame('card_declined', $charged->failureReason);
    }

    public function testARefundGoesToThePaymentIntentOfTheSession(): void
    {
        $this->http->expect('GET', self::API . '/checkout/sessions/cs_test_1', 200, json_encode(['id' => 'cs_test_1', 'payment_intent' => 'pi_1'], JSON_THROW_ON_ERROR));
        $this->http->expect('POST', self::API . '/refunds', 200, json_encode(['id' => 're_1', 'status' => 'succeeded'], JSON_THROW_ON_ERROR));

        $refund = $this->gateway()->refund(BillingFixtures::payment('stripe', 'cs_test_1', 1999), 500);

        self::assertTrue($refund->succeeded);
        self::assertSame('re_1', $refund->refundId);
        parse_str($this->http->requests[1]['options']['body'], $form);
        self::assertSame('pi_1', $form['payment_intent']);
        self::assertSame('500', $form['amount']);
    }

    public function testAValidSignedNotificationIsRead(): void
    {
        $event = $this->gateway()->parseWebhook($this->signed(self::event()));

        self::assertSame('evt_1', $event->eventId);
        self::assertSame('cs_test_1', $event->providerPaymentId);
        self::assertSame(1999, $event->amount);
    }

    public function testAWrongSignatureIsRejected(): void
    {
        $this->expectException(WebhookRejected::class);

        $this->gateway()->parseWebhook($this->signed(self::event(), null, 'whsec_other'));
    }

    public function testATamperedBodyIsRejected(): void
    {
        $request = $this->signed(self::event());
        $tampered = Request::create('POST', '/webhooks/billing/stripe', headers: ['Stripe-Signature' => (string) $request->header('Stripe-Signature')], rawBody: self::event(amount: 1));

        $this->expectException(WebhookRejected::class);

        $this->gateway()->parseWebhook($tampered);
    }

    public function testAnOldNotificationIsRejected(): void
    {
        $this->expectException(WebhookRejected::class);
        $this->expectExceptionMessage('too old');

        $this->gateway()->parseWebhook($this->signed(self::event(), $this->clock->now()->getTimestamp() - 301));
    }

    public function testAMissingSignatureHeaderIsRejected(): void
    {
        $this->expectException(WebhookRejected::class);

        $this->gateway()->parseWebhook(Request::create('POST', '/webhooks/billing/stripe', rawBody: self::event()));
    }

    public function testARejectedCredentialGivesACalmMessage(): void
    {
        $this->http->expect('GET', self::API . '/checkout/sessions/cs_x?expand[]=payment_intent.payment_method', 401, '{}');

        try {
            $this->gateway()->fetchStatus('cs_x');
            self::fail('Expected an exception.');
        } catch (GatewayException $e) {
            self::assertStringContainsString('настраивается', $e->forUser());
            self::assertStringNotContainsString('sk_test', $e->getMessage());
        }
    }
}
