<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payments;

use App\Domain\Billing\PaymentStatus;
use App\Integrations\Payments\Contracts\GatewayException;
use App\Integrations\Payments\Contracts\WebhookRejected;
use App\Integrations\Payments\Crypto\CryptoGateway;
use App\Kernel\Http\Request;
use App\Tests\Support\BillingFixtures;
use App\Tests\Support\MockHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CryptoGateway::class)]
final class CryptoGatewayTest extends TestCase
{
    private const API = 'https://crypto.test/v1';
    private const KEY = 'api-key';

    private MockHttpClient $http;

    protected function setUp(): void
    {
        $this->http = new MockHttpClient();
    }

    private function gateway(): CryptoGateway
    {
        return new CryptoGateway($this->http, 'merchant-uuid', self::KEY, 'https://app.example.com/webhooks/billing/crypto', ['USD'], self::API);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function notification(array $data, string $key = self::KEY): Request
    {
        $data['sign'] = md5(base64_encode(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) . $key);

        return Request::create('POST', '/webhooks/billing/crypto', headers: ['Content-Type' => 'application/json'], rawBody: json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private static function payload(string $status, string $amount = '19.99'): string
    {
        return json_encode(['state' => 0, 'result' => ['uuid' => 'u-1', 'payment_status' => $status, 'amount' => $amount, 'currency' => 'USD', 'url' => 'https://pay.example.com/pay/u-1']], JSON_THROW_ON_ERROR);
    }

    public function testAnInvoiceIsCreatedWithASignedRequest(): void
    {
        $this->http->expect('POST', self::API . '/payment', 200, self::payload('check'));

        $created = $this->gateway()->createPayment(BillingFixtures::invoice(1999), BillingFixtures::payment('crypto', null, 1999), 'https://app.example.com/back', false);

        self::assertSame('u-1', $created->providerPaymentId);
        self::assertSame(PaymentStatus::Pending, $created->status);
        self::assertSame('https://pay.example.com/pay/u-1', $created->confirmationUrl);
        $sent = $this->http->requests[0]['options'];
        self::assertSame('merchant-uuid', $sent['headers']['merchant']);
        self::assertSame(md5(base64_encode($sent['body']) . self::KEY), $sent['headers']['sign']);
        $body = json_decode($sent['body'], true);
        self::assertSame('19.99', $body['amount']);
        self::assertSame('01JTESTPAYMENT000000000000', $body['order_id']);
        self::assertSame('https://app.example.com/webhooks/billing/crypto', $body['url_callback']);
    }

    public function testStatusesAreMappedAndAnUnderpaymentIsNotSuccess(): void
    {
        $expected = ['paid' => PaymentStatus::Succeeded, 'paid_over' => PaymentStatus::Succeeded, 'wrong_amount' => PaymentStatus::Pending, 'check' => PaymentStatus::Pending, 'process' => PaymentStatus::Pending, 'cancel' => PaymentStatus::Failed, 'fail' => PaymentStatus::Failed];
        foreach ($expected as $raw => $status) {
            $this->http->expect('POST', self::API . '/payment/info', 200, self::payload($raw));

            self::assertSame($status, $this->gateway()->fetchStatus('u-1')->status, $raw);
        }
    }

    public function testThePaidAmountIsTheInvoiceAmount(): void
    {
        $this->http->expect('POST', self::API . '/payment/info', 200, self::payload('paid'));

        self::assertSame(1999, $this->gateway()->fetchStatus('u-1')->amount);
    }

    public function testThereIsNoAutomaticRenewalOrRefund(): void
    {
        self::assertFalse($this->gateway()->supportsRecurring());
        foreach ([
            fn () => $this->gateway()->chargeSaved(BillingFixtures::invoice(), BillingFixtures::payment('crypto'), BillingFixtures::method('crypto', 'x')),
            fn () => $this->gateway()->refund(BillingFixtures::payment('crypto', 'u-1'), 100),
        ] as $call) {
            try {
                $call();
                self::fail('Expected an exception.');
            } catch (GatewayException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testASignedNotificationIsReadAndATamperedOneIsNot(): void
    {
        $event = $this->gateway()->parseWebhook(self::notification(['uuid' => 'u-1', 'order_id' => 'o', 'status' => 'paid', 'amount' => '19.99']));
        self::assertSame('u-1', $event->providerPaymentId);
        self::assertSame('u-1:paid', $event->eventId);

        $this->expectException(WebhookRejected::class);
        $this->gateway()->parseWebhook(self::notification(['uuid' => 'u-1', 'status' => 'paid'], 'other-key'));
    }

    public function testANotificationWithoutASignatureIsRejected(): void
    {
        $this->expectException(WebhookRejected::class);

        $this->gateway()->parseWebhook(Request::create('POST', '/x', headers: ['Content-Type' => 'application/json'], rawBody: '{"uuid":"u-1","status":"paid"}'));
    }

    public function testAnErrorStateIsAnException(): void
    {
        $this->http->expect('POST', self::API . '/payment', 200, json_encode(['state' => 1, 'message' => 'bad'], JSON_THROW_ON_ERROR));

        $this->expectException(GatewayException::class);
        $this->gateway()->createPayment(BillingFixtures::invoice(), BillingFixtures::payment('crypto'), 'https://x', false);
    }
}
