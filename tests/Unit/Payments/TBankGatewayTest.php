<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payments;

use App\Domain\Billing\PaymentStatus;
use App\Integrations\Payments\Contracts\GatewayException;
use App\Integrations\Payments\Contracts\WebhookRejected;
use App\Integrations\Payments\TBank\TBankGateway;
use App\Integrations\Payments\TBank\TBankSigner;
use App\Kernel\Http\Request;
use App\Tests\Support\BillingFixtures;
use App\Tests\Support\MockHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TBankGateway::class)]
#[CoversClass(TBankSigner::class)]
final class TBankGatewayTest extends TestCase
{
    private MockHttpClient $http;

    protected function setUp(): void
    {
        $this->http = new MockHttpClient();
    }

    private function gateway(): TBankGateway
    {
        return new TBankGateway($this->http, BillingFixtures::TBANK_TERMINAL, BillingFixtures::TBANK_PASSWORD, BillingFixtures::TBANK_API, 'https://app.example.com/webhooks/billing/tbank', 'usn_income', 'none', 'Подписка ezposter');
    }

    /**
     * @param array<string, mixed> $body
     */
    private function webhook(array $body): Request
    {
        return Request::create('POST', '/webhooks/billing/tbank', body: $body, headers: ['Content-Type' => 'application/json'], server: ['REMOTE_ADDR' => '203.0.113.10']);
    }

    public function testTheTokenIsTheSha256OfSortedValuesAndThePassword(): void
    {
        // Worked example: sorted keys Amount, OrderId, Password, TerminalKey -> "1000" + "21050" + "Dfsfh56dgKl" + "TinkoffBankTest".
        $params = ['TerminalKey' => 'TinkoffBankTest', 'Amount' => '1000', 'OrderId' => '21050'];

        self::assertSame(hash('sha256', '100021050Dfsfh56dgKlTinkoffBankTest'), TBankSigner::token($params, 'Dfsfh56dgKl'));
    }

    public function testNestedObjectsAndTheTokenItselfAreNotSigned(): void
    {
        $plain = ['A' => '1', 'B' => 2];
        $withExtras = $plain + ['Receipt' => ['Items' => [1]], 'DATA' => ['Email' => 'a@b.c'], 'Token' => 'ignored', 'Nothing' => null];

        self::assertSame(TBankSigner::token($plain, 'p'), TBankSigner::token($withExtras, 'p'));
    }

    public function testBooleansAreSignedAsText(): void
    {
        // Keys sorted: Password, Success -> "p" + "true".
        self::assertSame(hash('sha256', 'ptrue'), TBankSigner::token(['Success' => true], 'p'));
        self::assertSame(hash('sha256', 'pfalse'), TBankSigner::token(['Success' => false], 'p'));
    }

    public function testInitSendsASignedRequestWithAReceiptAndRecurrence(): void
    {
        $this->http->expect('POST', BillingFixtures::TBANK_API . '/Init', 200, BillingFixtures::tbankRaw('init_ok'));

        $created = $this->gateway()->createPayment(BillingFixtures::invoice(), BillingFixtures::payment('tbank'), 'https://app.example.com/back', true);

        self::assertSame('2304884', $created->providerPaymentId);
        self::assertSame(PaymentStatus::Pending, $created->status);
        self::assertSame('https://securepay.tinkoff.ru/new/AbCdEf123', $created->confirmationUrl);
        $sent = $this->http->requests[0]['options']['json'];
        self::assertSame(99000, $sent['Amount'], 'T-Bank takes kopecks');
        self::assertSame('01JTESTPAYMENT000000000000', $sent['OrderId']);
        self::assertSame('Y', $sent['Recurrent']);
        self::assertSame('ws7', $sent['CustomerKey']);
        self::assertSame('https://app.example.com/webhooks/billing/tbank', $sent['NotificationURL']);
        self::assertSame('https://app.example.com/back', $sent['SuccessURL']);
        self::assertSame('owner@example.com', $sent['Receipt']['Email']);
        self::assertSame('usn_income', $sent['Receipt']['Taxation']);
        self::assertSame(99000, $sent['Receipt']['Items'][0]['Amount']);
        self::assertSame('service', $sent['Receipt']['Items'][0]['PaymentObject']);
        self::assertSame('none', $sent['Receipt']['Items'][0]['Tax']);
        self::assertSame(TBankSigner::token($sent, BillingFixtures::TBANK_PASSWORD), $sent['Token'], 'every request is signed');
        self::assertTrue(TBankSigner::verify($sent, BillingFixtures::TBANK_PASSWORD));
    }

    public function testWithoutTheCardBeingKeptNoRecurrenceIsRequested(): void
    {
        $this->http->expect('POST', BillingFixtures::TBANK_API . '/Init', 200, BillingFixtures::tbankRaw('init_ok'));

        $this->gateway()->createPayment(BillingFixtures::invoice(), BillingFixtures::payment('tbank'), 'https://x', false);

        self::assertArrayNotHasKey('Recurrent', $this->http->requests[0]['options']['json']);
    }

    public function testStatusesAreMapped(): void
    {
        $this->http->expect('POST', BillingFixtures::TBANK_API . '/GetState', 200, BillingFixtures::tbankRaw('getstate_confirmed'));
        $this->http->expect('POST', BillingFixtures::TBANK_API . '/GetState', 200, BillingFixtures::tbankRaw('getstate_rejected'));
        $this->http->expect('POST', BillingFixtures::TBANK_API . '/GetState', 200, BillingFixtures::tbankRaw('getstate_confirmed', ['Status' => 'AUTHORIZED']));
        $this->http->expect('POST', BillingFixtures::TBANK_API . '/GetState', 200, BillingFixtures::tbankRaw('getstate_confirmed', ['Status' => '3DS_CHECKING']));
        $this->http->expect('POST', BillingFixtures::TBANK_API . '/GetState', 200, BillingFixtures::tbankRaw('getstate_confirmed', ['Status' => 'PARTIAL_REFUNDED']));

        $gateway = $this->gateway();

        self::assertSame(PaymentStatus::Succeeded, $gateway->fetchStatus('2304884')->status);
        self::assertSame(PaymentStatus::Failed, $gateway->fetchStatus('2304884')->status);
        self::assertSame(PaymentStatus::Pending, $gateway->fetchStatus('2304884')->status, 'authorized is not yet money');
        self::assertSame(PaymentStatus::Pending, $gateway->fetchStatus('2304884')->status);
        self::assertSame(PaymentStatus::Succeeded, $gateway->fetchStatus('2304884')->status, 'a partial refund leaves the payment paid');
    }

    public function testAChargeByRebillIdInitialisesThenCharges(): void
    {
        $this->http->expect('POST', BillingFixtures::TBANK_API . '/Init', 200, BillingFixtures::tbankRaw('init_ok'));
        $this->http->expect('POST', BillingFixtures::TBANK_API . '/Charge', 200, BillingFixtures::tbankRaw('charge_confirmed'));

        $paid = $this->gateway()->chargeSaved(BillingFixtures::invoice(), BillingFixtures::payment('tbank'), BillingFixtures::method('tbank', '1700000000001'));

        self::assertSame(PaymentStatus::Succeeded, $paid->status);
        self::assertSame('1700000000001', $this->http->requests[1]['options']['json']['RebillId']);
        self::assertSame('2304884', $this->http->requests[1]['options']['json']['PaymentId'], 'the charge belongs to the payment that was just created');
    }

    public function testADeclinedChargeIsAnAnswerNotAnOutage(): void
    {
        $this->http->expect('POST', BillingFixtures::TBANK_API . '/Init', 200, BillingFixtures::tbankRaw('init_ok'));
        $this->http->expect('POST', BillingFixtures::TBANK_API . '/Charge', 200, BillingFixtures::tbankRaw('charge_rejected'));

        $result = $this->gateway()->chargeSaved(BillingFixtures::invoice(), BillingFixtures::payment('tbank'), BillingFixtures::method('tbank', '1700000000001'));

        self::assertSame(PaymentStatus::Failed, $result->status);
        self::assertStringContainsString('REJECTED', (string) $result->failureReason);
    }

    public function testALostChargeAnswerKeepsThePaymentIdSoItsStatusCanBeChecked(): void
    {
        $this->http->expect('POST', BillingFixtures::TBANK_API . '/Init', 200, BillingFixtures::tbankRaw('init_ok'));
        $this->http->expect('POST', BillingFixtures::TBANK_API . '/Charge', 503, '');

        $result = $this->gateway()->chargeSaved(BillingFixtures::invoice(), BillingFixtures::payment('tbank'), BillingFixtures::method('tbank', '1700000000001'));

        self::assertSame(PaymentStatus::Pending, $result->status);
        self::assertSame('2304884', $result->providerPaymentId, 'the money may have moved: the status check decides');
    }

    public function testAnInitThatFailsRaisesWithTheProviderCodeInTheLogOnly(): void
    {
        $this->http->expect('POST', BillingFixtures::TBANK_API . '/Init', 200, BillingFixtures::tbankRaw('error_terminal'));

        try {
            $this->gateway()->createPayment(BillingFixtures::invoice(), BillingFixtures::payment('tbank'), 'https://x', true);
            self::fail('must raise');
        } catch (GatewayException $e) {
            self::assertStringContainsString('code 7', $e->getMessage());
            self::assertStringNotContainsString('Терминал', $e->forUser());
            self::assertFalse($e->retryable);
        }
    }

    public function testRefundsCallCancelWithTheAmountOnlyWhenPartial(): void
    {
        $this->http->expect('POST', BillingFixtures::TBANK_API . '/Cancel', 200, BillingFixtures::tbankRaw('cancel_refunded'));
        $this->http->expect('POST', BillingFixtures::TBANK_API . '/Cancel', 200, BillingFixtures::tbankRaw('cancel_refunded', ['Status' => 'PARTIAL_REFUNDED']));
        $payment = BillingFixtures::payment('tbank', '2304884');

        $full = $this->gateway()->refund($payment, 99000);
        $partial = $this->gateway()->refund($payment, 10000);

        self::assertTrue($full->succeeded);
        self::assertArrayNotHasKey('Amount', $this->http->requests[0]['options']['json']);
        self::assertTrue($partial->succeeded);
        self::assertSame(10000, $this->http->requests[1]['options']['json']['Amount']);
    }

    public function testAGenuineNotificationIsAcceptedAndCarriesTheRebillId(): void
    {
        $event = $this->gateway()->parseWebhook($this->webhook(BillingFixtures::tbankNotification()));

        self::assertSame('2304884:CONFIRMED', $event->eventId);
        self::assertSame('2304884', $event->providerPaymentId);
        self::assertSame(99000, $event->amount);
        self::assertSame('1700000000001', $event->method?->providerMethodId);
        self::assertSame('Карта •• 0777', $event->method->title);
    }

    public function testATamperedNotificationIsRejected(): void
    {
        $body = BillingFixtures::tbankNotification();
        $body['Amount'] = 1;

        $this->expectException(WebhookRejected::class);
        $this->gateway()->parseWebhook($this->webhook($body));
    }

    public function testNotificationsSignedWithAnotherPasswordAreRejected(): void
    {
        $this->expectException(WebhookRejected::class);
        $this->gateway()->parseWebhook($this->webhook(BillingFixtures::tbankNotification([], 'someone-elses-password')));
    }

    public function testNotificationsWithoutATokenOrForAnotherTerminalAreRejected(): void
    {
        $noToken = BillingFixtures::tbankNotification();
        unset($noToken['Token']);
        foreach ([$noToken, BillingFixtures::tbankNotification(['TerminalKey' => 'OtherTerminal'])] as $body) {
            try {
                $this->gateway()->parseWebhook($this->webhook($body));
                self::fail('must be rejected');
            } catch (WebhookRejected) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testTheAcknowledgementIsThePlainTextOk(): void
    {
        $ack = $this->gateway()->webhookAck();

        self::assertSame('OK', $ack->body);
        self::assertSame(200, $ack->status);
    }
}
