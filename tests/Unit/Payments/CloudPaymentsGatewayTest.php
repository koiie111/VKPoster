<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payments;

use App\Domain\Billing\PaymentStatus;
use App\Integrations\Payments\CloudPayments\CloudPaymentsGateway;
use App\Integrations\Payments\Contracts\WebhookRejected;
use App\Kernel\Http\Request;
use App\Tests\Support\BillingFixtures;
use App\Tests\Support\MockHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CloudPaymentsGateway::class)]
final class CloudPaymentsGatewayTest extends TestCase
{
    private const API = 'https://cp.test';
    private const SECRET = 'api-secret';

    private MockHttpClient $http;

    protected function setUp(): void
    {
        $this->http = new MockHttpClient();
    }

    private function gateway(): CloudPaymentsGateway
    {
        return new CloudPaymentsGateway($this->http, 'pk_test', self::SECRET, ['RUB'], self::API);
    }

    /**
     * @param array<string, string> $fields
     */
    private static function notification(array $fields, string $type = 'pay', string $secret = self::SECRET, ?string $header = null): Request
    {
        $raw = http_build_query($fields);

        return Request::create('POST', '/webhooks/billing/cloudpayments', query: ['type' => $type], body: $fields, headers: ['Content-HMAC' => $header ?? base64_encode(hash_hmac('sha256', $raw, $secret, true))], rawBody: $raw);
    }

    public function testAnOrderPageIsCreatedAndOurPaymentIdIsTheInvoiceId(): void
    {
        $this->http->expect('POST', self::API . '/orders/create', 200, json_encode(['Success' => true, 'Model' => ['Id' => 'ord1', 'Url' => 'https://orders.cloudpayments.ru/d/ord1']], JSON_THROW_ON_ERROR));

        $created = $this->gateway()->createPayment(BillingFixtures::invoice(99000), BillingFixtures::payment('cloudpayments'), 'https://app.example.com/back', true);

        self::assertSame('01JTESTPAYMENT000000000000', $created->providerPaymentId);
        self::assertSame('https://orders.cloudpayments.ru/d/ord1', $created->confirmationUrl);
        $sent = $this->http->requests[0]['options'];
        self::assertSame(['pk_test', self::SECRET], $sent['auth']);
        self::assertSame(990.0, $sent['json']['Amount']);
        self::assertSame('01JTESTPAYMENT000000000000', $sent['json']['InvoiceId']);
        self::assertSame('ws7', $sent['json']['AccountId']);
    }

    public function testACompletedTransactionIsSucceededWithItsToken(): void
    {
        $this->http->expect('POST', self::API . '/payments/find', 200, json_encode(['Success' => true, 'Model' => ['TransactionId' => 77, 'Status' => 'Completed', 'Amount' => 990.0, 'Currency' => 'RUB', 'Token' => 'tk_1', 'CardLastFour' => '4242', 'CardType' => 'Visa', 'AccountId' => 'ws7']], JSON_THROW_ON_ERROR));

        $status = $this->gateway()->fetchStatus('01JTESTPAYMENT000000000000');

        self::assertSame(PaymentStatus::Succeeded, $status->status);
        self::assertSame(99000, $status->amount);
        self::assertNotNull($status->method);
        self::assertSame('tk_1', $status->method->providerMethodId);
        self::assertSame('Visa •• 4242', $status->method->title);
    }

    public function testNoTransactionYetMeansPendingAndADeclineMeansFailed(): void
    {
        $this->http->expect('POST', self::API . '/payments/find', 200, json_encode(['Success' => false, 'Message' => 'Not found'], JSON_THROW_ON_ERROR));
        self::assertSame(PaymentStatus::Pending, $this->gateway()->fetchStatus('x')->status);

        $this->http->expect('POST', self::API . '/payments/find', 200, json_encode(['Success' => true, 'Model' => ['Status' => 'Declined', 'Amount' => 990.0, 'Currency' => 'RUB', 'Reason' => 'InsufficientFunds']], JSON_THROW_ON_ERROR));
        $failed = $this->gateway()->fetchStatus('x');
        self::assertSame(PaymentStatus::Failed, $failed->status);
        self::assertSame('InsufficientFunds', $failed->failureReason);
    }

    public function testARenewalChargesTheToken(): void
    {
        $this->http->expect('POST', self::API . '/payments/tokens/charge', 200, json_encode(['Success' => true, 'Model' => ['TransactionId' => 78]], JSON_THROW_ON_ERROR));

        $charged = $this->gateway()->chargeSaved(BillingFixtures::invoice(), BillingFixtures::payment('cloudpayments'), BillingFixtures::method('cloudpayments', 'tk_1'));

        self::assertSame(PaymentStatus::Succeeded, $charged->status);
        self::assertSame('tk_1', $this->http->requests[0]['options']['json']['Token']);

        $this->http->expect('POST', self::API . '/payments/tokens/charge', 200, json_encode(['Success' => false, 'Message' => 'Refer to card issuer', 'Model' => ['Reason' => 'ReferToCardIssuer']], JSON_THROW_ON_ERROR));
        $declined = $this->gateway()->chargeSaved(BillingFixtures::invoice(), BillingFixtures::payment('cloudpayments'), BillingFixtures::method('cloudpayments', 'tk_1'));
        self::assertSame(PaymentStatus::Failed, $declined->status);
    }

    public function testARefundFindsTheTransactionFirst(): void
    {
        $this->http->expect('POST', self::API . '/payments/find', 200, json_encode(['Success' => true, 'Model' => ['TransactionId' => 77, 'Status' => 'Completed', 'Amount' => 990.0, 'Currency' => 'RUB']], JSON_THROW_ON_ERROR));
        $this->http->expect('POST', self::API . '/payments/refund', 200, json_encode(['Success' => true, 'Model' => ['TransactionId' => 79]], JSON_THROW_ON_ERROR));

        $refund = $this->gateway()->refund(BillingFixtures::payment('cloudpayments', '01JTESTPAYMENT000000000000'), 50000);

        self::assertTrue($refund->succeeded);
        self::assertSame(77, $this->http->requests[1]['options']['json']['TransactionId']);
        self::assertSame(500.0, $this->http->requests[1]['options']['json']['Amount']);
    }

    public function testASignedPayNotificationIsReadWithItsCard(): void
    {
        $event = $this->gateway()->parseWebhook(self::notification(['TransactionId' => '77', 'Amount' => '990.00', 'InvoiceId' => '01JTESTPAYMENT000000000000', 'Token' => 'tk_1', 'CardLastFour' => '4242', 'CardType' => 'Visa']));

        self::assertSame('pay:77', $event->eventId);
        self::assertSame('01JTESTPAYMENT000000000000', $event->providerPaymentId);
        self::assertSame(99000, $event->amount);
        self::assertNotNull($event->method);
        self::assertSame('tk_1', $event->method->providerMethodId);
        self::assertSame('{"code":0}', $this->gateway()->webhookAck()->body);
    }

    public function testAWrongSignatureOrNoSignatureIsRejected(): void
    {
        foreach ([
            self::notification(['TransactionId' => '77', 'InvoiceId' => 'x'], 'pay', 'other-secret'),
            self::notification(['TransactionId' => '77', 'InvoiceId' => 'x'], 'pay', self::SECRET, ''),
        ] as $request) {
            try {
                $this->gateway()->parseWebhook($request);
                self::fail('Expected a rejection.');
            } catch (WebhookRejected) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAnUnknownTypeInTheQueryIsRejected(): void
    {
        $this->expectException(WebhookRejected::class);

        $this->gateway()->parseWebhook(self::notification(['TransactionId' => '77', 'InvoiceId' => 'x'], 'PAY;DROP'));
    }
}
