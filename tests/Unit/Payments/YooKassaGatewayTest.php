<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payments;

use App\Domain\Billing\PaymentStatus;
use App\Integrations\Payments\Contracts\GatewayException;
use App\Integrations\Payments\Contracts\WebhookRejected;
use App\Integrations\Payments\YooKassa\YooKassaGateway;
use App\Kernel\Http\Request;
use App\Tests\Support\BillingFixtures;
use App\Tests\Support\MockHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(YooKassaGateway::class)]
final class YooKassaGatewayTest extends TestCase
{
    private MockHttpClient $http;

    protected function setUp(): void
    {
        $this->http = new MockHttpClient();
    }

    private function gateway(string $vat = 'none', string $tax = 'usn_income', bool $verifyIp = true): YooKassaGateway
    {
        return new YooKassaGateway($this->http, '100500', 'secret-key', BillingFixtures::YOOKASSA_API, $verifyIp, $tax, $vat, 'Подписка ezposter');
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function webhook(string $ip, ?array $body = null): Request
    {
        return Request::create('POST', '/webhooks/billing/yookassa', body: $body ?? BillingFixtures::yookassa('webhook_payment_succeeded'), headers: ['Content-Type' => 'application/json'], server: ['REMOTE_ADDR' => $ip]);
    }

    public function testCreatesAPaymentWithAReceiptAndTheCardSaved(): void
    {
        $this->http->expect('POST', BillingFixtures::YOOKASSA_API . '/payments', 200, BillingFixtures::yookassaRaw('payment_pending'));

        $created = $this->gateway()->createPayment(BillingFixtures::invoice(), BillingFixtures::payment('yookassa'), 'https://app.example.com/back', true);

        self::assertSame(BillingFixtures::YOOKASSA_PAYMENT, $created->providerPaymentId);
        self::assertSame(PaymentStatus::Pending, $created->status);
        self::assertStringStartsWith('https://yoomoney.ru/', (string) $created->confirmationUrl);
        $call = $this->http->requests[0];
        self::assertSame(['100500', 'secret-key'], $call['options']['auth']);
        self::assertSame('01JTESTPAYMENT000000000000', $call['options']['headers']['Idempotence-Key'], 'a repeated request must not create a second payment');
        $body = $call['options']['json'];
        self::assertSame(['value' => '990.00', 'currency' => 'RUB'], $body['amount']);
        self::assertTrue($body['capture']);
        self::assertTrue($body['save_payment_method']);
        self::assertSame(['type' => 'redirect', 'return_url' => 'https://app.example.com/back'], $body['confirmation']);
        self::assertSame('owner@example.com', $body['receipt']['customer']['email']);
        self::assertSame(2, $body['receipt']['tax_system_code'], 'simplified tax system, income');
        $item = $body['receipt']['items'][0];
        self::assertSame(1, $item['vat_code'], 'no VAT');
        self::assertSame('service', $item['payment_subject']);
        self::assertSame('full_payment', $item['payment_mode']);
        self::assertSame('1.00', $item['quantity']);
        self::assertSame(['value' => '990.00', 'currency' => 'RUB'], $item['amount']);
    }

    public function testTaxSettingsAreMappedToYooKassaCodes(): void
    {
        $this->http->expect('POST', BillingFixtures::YOOKASSA_API . '/payments', 200, BillingFixtures::yookassaRaw('payment_pending'));

        $this->gateway('vat20', 'osn')->createPayment(BillingFixtures::invoice(), BillingFixtures::payment('yookassa'), 'https://x', false);

        $body = $this->http->requests[0]['options']['json'];
        self::assertSame(1, $body['receipt']['tax_system_code']);
        self::assertSame(4, $body['receipt']['items'][0]['vat_code']);
        self::assertFalse($body['save_payment_method']);
    }

    public function testChargesASavedMethodWithoutAConfirmation(): void
    {
        $this->http->expect('POST', BillingFixtures::YOOKASSA_API . '/payments', 200, BillingFixtures::yookassaRaw('payment_succeeded'));

        $paid = $this->gateway()->chargeSaved(BillingFixtures::invoice(), BillingFixtures::payment('yookassa'), BillingFixtures::method('yookassa', 'saved-method-id'));

        self::assertSame(PaymentStatus::Succeeded, $paid->status);
        $body = $this->http->requests[0]['options']['json'];
        self::assertSame('saved-method-id', $body['payment_method_id']);
        self::assertArrayNotHasKey('confirmation', $body);
        self::assertTrue($body['capture']);
    }

    public function testASucceededPaymentCarriesTheSavedCard(): void
    {
        $this->http->expect('GET', BillingFixtures::YOOKASSA_API . '/payments/' . BillingFixtures::YOOKASSA_PAYMENT, 200, BillingFixtures::yookassaRaw('payment_succeeded'));

        $status = $this->gateway()->fetchStatus(BillingFixtures::YOOKASSA_PAYMENT);

        self::assertSame(PaymentStatus::Succeeded, $status->status);
        self::assertSame(99000, $status->amount);
        self::assertSame('MasterCard •• 4477', $status->method?->title);
        self::assertSame(BillingFixtures::YOOKASSA_PAYMENT, $status->method->providerMethodId);
    }

    public function testACardThatWasNotSavedIsNotOfferedForRenewals(): void
    {
        $this->http->expect('GET', BillingFixtures::YOOKASSA_API . '/payments/x', 200, BillingFixtures::yookassaRaw('payment_pending', ['status' => 'succeeded']));

        self::assertNull($this->gateway()->fetchStatus('x')->method);
    }

    public function testACanceledPaymentIsAFailureWithItsReason(): void
    {
        $this->http->expect('GET', BillingFixtures::YOOKASSA_API . '/payments/y', 200, BillingFixtures::yookassaRaw('payment_canceled'));

        $status = $this->gateway()->fetchStatus('y');

        self::assertSame(PaymentStatus::Failed, $status->status);
        self::assertSame('insufficient_funds', $status->failureReason);
    }

    public function testRefundsReturnTheMoney(): void
    {
        $this->http->expect('POST', BillingFixtures::YOOKASSA_API . '/refunds', 200, BillingFixtures::yookassaRaw('refund_succeeded'));

        $refund = $this->gateway()->refund(BillingFixtures::payment('yookassa', BillingFixtures::YOOKASSA_PAYMENT), 99000);

        self::assertTrue($refund->succeeded);
        self::assertSame(99000, $refund->amount);
        self::assertSame(BillingFixtures::YOOKASSA_PAYMENT, $this->http->requests[0]['options']['json']['payment_id']);
    }

    public function testNotificationsFromYooKassaAddressesAreAccepted(): void
    {
        $event = $this->gateway()->parseWebhook($this->webhook(BillingFixtures::YOOKASSA_IP));

        self::assertSame('yookassa', $event->provider);
        self::assertSame('payment.succeeded:' . BillingFixtures::YOOKASSA_PAYMENT, $event->eventId);
        self::assertSame(BillingFixtures::YOOKASSA_PAYMENT, $event->providerPaymentId);
        self::assertSame(99000, $event->amount);
    }

    public function testNotificationsFromAnyOtherAddressAreRejected(): void
    {
        $this->expectException(WebhookRejected::class);
        $this->gateway()->parseWebhook($this->webhook('203.0.113.10'));
    }

    public function testTheAddressCheckCanBeSwitchedOffForTunnelsInDevelopment(): void
    {
        $event = $this->gateway('none', 'usn_income', false)->parseWebhook($this->webhook('203.0.113.10'));

        self::assertSame('payment.succeeded', $event->type);
    }

    public function testUnreadableNotificationsAreRejected(): void
    {
        $this->expectException(WebhookRejected::class);
        $this->gateway()->parseWebhook($this->webhook(BillingFixtures::YOOKASSA_IP, ['type' => 'notification', 'event' => 'payment.succeeded']));
    }

    public function testARefundNotificationPointsAtItsPayment(): void
    {
        $body = ['type' => 'notification', 'event' => 'refund.succeeded', 'object' => ['id' => 'refund-1', 'payment_id' => 'pay-1', 'amount' => ['value' => '10.00', 'currency' => 'RUB']]];

        $event = $this->gateway()->parseWebhook($this->webhook(BillingFixtures::YOOKASSA_IP, $body));

        self::assertSame('pay-1', $event->providerPaymentId);
    }

    public function testAnAnswerWithoutAnAmountIsAnError(): void
    {
        $this->http->expect('GET', BillingFixtures::YOOKASSA_API . '/payments/z', 200, '{"id":"z","status":"succeeded"}');

        $this->expectException(GatewayException::class);
        $this->gateway()->fetchStatus('z');
    }

    public function testClientErrorsAreNotRetryableAndTheTextIsNotShown(): void
    {
        $this->http->expect('POST', BillingFixtures::YOOKASSA_API . '/payments', 400, BillingFixtures::yookassaRaw('error_invalid_request'));

        try {
            $this->gateway()->createPayment(BillingFixtures::invoice(), BillingFixtures::payment('yookassa'), 'https://x', true);
            self::fail('a rejected order must raise');
        } catch (GatewayException $e) {
            self::assertFalse($e->retryable);
            self::assertStringContainsString('invalid_request', $e->getMessage());
            self::assertStringNotContainsString('Receipt', $e->forUser(), 'the provider\'s wording never reaches the customer');
        }
    }

    public function testServerErrorsAndLimitsAreRetryable(): void
    {
        $this->http->expect('GET', BillingFixtures::YOOKASSA_API . '/payments/a', 503, '');
        $this->http->expect('GET', BillingFixtures::YOOKASSA_API . '/payments/b', 429, '{}');

        foreach (['a', 'b'] as $id) {
            try {
                $this->gateway()->fetchStatus($id);
                self::fail('must raise');
            } catch (GatewayException $e) {
                self::assertTrue($e->retryable);
            }
        }
    }

    public function testWrongCredentialsAreReportedAsAnAccountProblemNotAsAnOutage(): void
    {
        $this->http->expect('GET', BillingFixtures::YOOKASSA_API . '/payments/c', 401, '{"type":"error","code":"invalid_credentials"}');

        try {
            $this->gateway()->fetchStatus('c');
            self::fail('must raise');
        } catch (GatewayException $e) {
            self::assertFalse($e->retryable);
            self::assertStringContainsString('401', $e->getMessage());
        }
    }

    public function testItIsConfiguredOnlyWithBothCredentials(): void
    {
        self::assertTrue($this->gateway()->isConfigured());
        self::assertFalse((new YooKassaGateway($this->http, '', 'k', BillingFixtures::YOOKASSA_API, true, 'osn', 'none', 'x'))->isConfigured());
        self::assertFalse((new YooKassaGateway($this->http, '1', '', BillingFixtures::YOOKASSA_API, true, 'osn', 'none', 'x'))->isConfigured());
    }
}
