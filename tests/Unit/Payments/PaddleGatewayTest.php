<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payments;

use App\Domain\Billing\PaymentStatus;
use App\Integrations\Payments\Contracts\GatewayException;
use App\Integrations\Payments\Contracts\WebhookRejected;
use App\Integrations\Payments\Paddle\PaddleGateway;
use App\Kernel\Http\Request;
use App\Tests\Support\BillingFixtures;
use App\Tests\Support\FakeClock;
use App\Tests\Support\MockHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaddleGateway::class)]
final class PaddleGatewayTest extends TestCase
{
    private const API = 'https://paddle.test';
    private const SECRET = 'pdl_ntfset_x';

    private MockHttpClient $http;
    private FakeClock $clock;

    protected function setUp(): void
    {
        $this->http = new MockHttpClient();
        $this->clock = new FakeClock('2026-10-18 12:00:00');
    }

    private function gateway(bool $sandbox = false): PaddleGateway
    {
        return new PaddleGateway($this->http, 'pdl_live_key', self::SECRET, $this->clock, $sandbox, ['USD'], self::API);
    }

    private function signed(string $body, ?int $timestamp = null, string $secret = self::SECRET): Request
    {
        $timestamp ??= $this->clock->now()->getTimestamp();

        return Request::create('POST', '/webhooks/billing/paddle', headers: ['Paddle-Signature' => 'ts=' . $timestamp . ';h1=' . hash_hmac('sha256', $timestamp . ':' . $body, $secret)], rawBody: $body);
    }

    private static function transaction(string $status, string $total = '1999'): string
    {
        return json_encode(['data' => ['id' => 'txn_1', 'status' => $status, 'currency_code' => 'USD', 'checkout' => ['url' => 'https://pay.paddle.io/hsc_1'], 'details' => ['totals' => ['grand_total' => $total], 'line_items' => [['id' => 'txnitm_1']]]]], JSON_THROW_ON_ERROR);
    }

    public function testItNeedsBothKeysAndDoesNotRenewByItself(): void
    {
        self::assertTrue($this->gateway()->isConfigured());
        self::assertFalse((new PaddleGateway($this->http, '', 's', $this->clock))->isConfigured());
        self::assertFalse($this->gateway()->supportsRecurring());
        $this->expectException(GatewayException::class);
        $this->gateway()->chargeSaved(BillingFixtures::invoice(), BillingFixtures::payment('paddle'), BillingFixtures::method('paddle', 'x'));
    }

    public function testATransactionIsCreatedWithATaxInclusivePrice(): void
    {
        $this->http->expect('POST', self::API . '/transactions', 200, self::transaction('ready'));

        $created = $this->gateway()->createPayment(BillingFixtures::invoice(1999), BillingFixtures::payment('paddle', null, 1999), 'https://app.example.com/back', false);

        self::assertSame('txn_1', $created->providerPaymentId);
        self::assertSame('https://pay.paddle.io/hsc_1', $created->confirmationUrl);
        $sent = $this->http->requests[0]['options'];
        self::assertSame('Bearer pdl_live_key', $sent['headers']['Authorization']);
        self::assertSame('internal', $sent['json']['items'][0]['price']['tax_mode']);
        self::assertSame('1999', $sent['json']['items'][0]['price']['unit_price']['amount']);
        self::assertSame('01JTESTPAYMENT000000000000', $sent['json']['custom_data']['payment']);
    }

    public function testTheSandboxHasItsOwnAddress(): void
    {
        $gateway = new PaddleGateway($this->http, 'k', 's', $this->clock, true, ['USD']);
        $this->http->expect('GET', 'https://sandbox-api.paddle.com/transactions/txn_1', 200, self::transaction('paid'));

        self::assertSame(PaymentStatus::Succeeded, $gateway->fetchStatus('txn_1')->status);
    }

    public function testStatusesAreMapped(): void
    {
        foreach (['paid' => PaymentStatus::Succeeded, 'completed' => PaymentStatus::Succeeded, 'canceled' => PaymentStatus::Failed, 'ready' => PaymentStatus::Pending, 'billed' => PaymentStatus::Pending] as $raw => $expected) {
            $this->http->expect('GET', self::API . '/transactions/txn_1', 200, self::transaction($raw));

            self::assertSame($expected, $this->gateway()->fetchStatus('txn_1')->status, $raw);
        }
    }

    public function testAFullRefundAndAPartialOne(): void
    {
        $this->http->expect('POST', self::API . '/adjustments', 200, json_encode(['data' => ['id' => 'adj_1', 'status' => 'approved']], JSON_THROW_ON_ERROR));
        self::assertTrue($this->gateway()->refund(BillingFixtures::payment('paddle', 'txn_1', 1999), 1999)->succeeded);
        self::assertSame('full', $this->http->requests[0]['options']['json']['type']);

        $this->http->expect('GET', self::API . '/transactions/txn_1', 200, self::transaction('completed'));
        $this->http->expect('POST', self::API . '/adjustments', 200, json_encode(['data' => ['id' => 'adj_2', 'status' => 'pending_approval']], JSON_THROW_ON_ERROR));
        $partial = $this->gateway()->refund(BillingFixtures::payment('paddle', 'txn_1', 1999), 500);
        self::assertFalse($partial->succeeded, 'a refund waiting for approval is not done yet');
        self::assertSame('500', $this->http->requests[2]['options']['json']['items'][0]['amount']);
    }

    public function testAValidSignedNotificationNamesTheTransaction(): void
    {
        $body = json_encode(['event_id' => 'evt_01', 'event_type' => 'transaction.completed', 'data' => ['id' => 'txn_1']], JSON_THROW_ON_ERROR);

        $event = $this->gateway()->parseWebhook($this->signed($body));

        self::assertSame('evt_01', $event->eventId);
        self::assertSame('txn_1', $event->providerPaymentId);
    }

    public function testARefundNotificationNamesItsTransaction(): void
    {
        $body = json_encode(['event_id' => 'evt_02', 'event_type' => 'adjustment.updated', 'data' => ['id' => 'adj_1', 'transaction_id' => 'txn_1']], JSON_THROW_ON_ERROR);

        self::assertSame('txn_1', $this->gateway()->parseWebhook($this->signed($body))->providerPaymentId);
    }

    public function testWrongOldAndMissingSignaturesAreRejected(): void
    {
        $body = json_encode(['event_id' => 'e', 'event_type' => 'transaction.paid', 'data' => ['id' => 'txn_1']], JSON_THROW_ON_ERROR);
        $bad = [
            $this->signed($body, null, 'other'),
            $this->signed($body, $this->clock->now()->getTimestamp() - 400),
            Request::create('POST', '/x', rawBody: $body),
        ];
        foreach ($bad as $request) {
            try {
                $this->gateway()->parseWebhook($request);
                self::fail('Expected a rejection.');
            } catch (WebhookRejected) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
