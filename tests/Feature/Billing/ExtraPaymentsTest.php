<?php

declare(strict_types=1);

namespace App\Tests\Feature\Billing;

use App\Domain\Billing\AddonRepository;
use App\Domain\Billing\BillingException;
use App\Domain\Billing\BillingPeriod;
use App\Domain\Billing\BillingService;
use App\Domain\Billing\Entitlements;
use App\Domain\Billing\Ledger;
use App\Domain\Billing\PromoRepository;
use App\Domain\Billing\PromoService;
use App\Domain\Billing\StarsPayments;
use App\Domain\User\User;
use App\Domain\Workspace\Workspace;
use App\Integrations\Payments\ProviderSettings;
use App\Tests\Support\BillingTestCase;
use App\Tests\Support\TestEnv;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Stage 16: paying in other currencies through the added providers, promo codes, add-on packages and the Telegram Stars updates.
 */
#[CoversClass(BillingService::class)]
#[CoversClass(PromoService::class)]
#[CoversClass(StarsPayments::class)]
final class ExtraPaymentsTest extends BillingTestCase
{
    private User $owner;
    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanUp();
        [$this->owner, $this->workspace] = $this->ownerWithWorkspace();
        $this->givePlan($this->workspace, 'free');
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    /** What these tests add to shared tables (other currencies, codes, packages, provider switches) must not outlive them. */
    private function cleanUp(): void
    {
        $this->db->execute("DELETE FROM plan_prices WHERE currency <> 'RUB'");
        $this->db->execute('DELETE FROM workspace_addons');
        $this->db->execute('DELETE FROM addon_packages');
        $this->db->execute('DELETE FROM promo_redemptions');
        $this->db->execute('DELETE FROM promo_codes');
        $this->db->execute('DELETE FROM payment_providers');
    }

    private function price(string $plan = 'pro'): int
    {
        return $this->plans()->findByCode($plan)?->priceFor(BillingPeriod::Month) ?? self::fail('no price');
    }

    private function promos(): PromoRepository
    {
        return $this->app->container()->get(PromoRepository::class);
    }

    /**
     * @param array<string, string> $fields
     */
    private function enable(string $provider, array $fields = []): void
    {
        $errors = $this->app->container()->get(ProviderSettings::class)->save($provider, true, $fields, null);
        self::assertSame([], $errors);
    }

    private function setPrice(string $plan, string $currency, int $amount, string $period = 'month'): void
    {
        $id = ($this->plans()->findByCode($plan) ?? self::fail('no plan'))->id;
        $this->db->table('plan_prices')->insert(['plan_id' => $id, 'period' => $period, 'currency' => $currency, 'amount' => $amount]);
    }

    private function checkout(string $plan = 'pro', string $gateway = 'fake', ?string $currency = null, ?string $promo = null, ?User $by = null, ?Workspace $in = null): \App\Domain\Billing\CheckoutResult
    {
        $workspace = $in ?? $this->workspace;
        $user = $by ?? $this->owner;

        return $this->billing()->checkout($this->contextFor($workspace, $user), $user, $plan, BillingPeriod::Month, $gateway, false, $currency, $promo);
    }

    // ---- other currencies ----------------------------------------------------------------------------------------

    public function testStripeChargesInDollarsAndTheSubscriptionKeepsTheCurrency(): void
    {
        $this->setPrice('pro', 'USD', 1999);
        $this->enable('stripe', ['secret_key' => 'sk_test_x', 'webhook_secret' => 'whsec_x']);
        $this->http->expect('POST', 'https://api.stripe.com/v1/checkout/sessions', 200, json_encode(['id' => 'cs_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_1', 'payment_status' => 'unpaid', 'status' => 'open', 'amount_total' => 1999, 'currency' => 'usd'], JSON_THROW_ON_ERROR));

        $result = $this->checkout('pro', 'stripe', 'USD');

        self::assertSame('https://checkout.stripe.com/c/pay/cs_1', $result->redirectUrl);
        $payment = $this->paymentsRepo()->findByPublicId((string) $result->paymentId) ?? self::fail('no payment');
        self::assertSame('stripe', $payment->provider);
        self::assertSame('USD', $payment->currency);
        self::assertSame(1999, $payment->amount);

        $this->http->expect('GET', 'https://api.stripe.com/v1/checkout/sessions/cs_1?expand[]=payment_intent.payment_method', 200, json_encode(['id' => 'cs_1', 'payment_status' => 'paid', 'status' => 'complete', 'amount_total' => 1999, 'currency' => 'usd'], JSON_THROW_ON_ERROR));
        $this->billing()->sync($payment);

        $subscription = $this->subscription($this->workspace);
        self::assertSame('USD', $subscription->currency);
        self::assertSame(1999, $subscription->priceAmount);
    }

    public function testAPaidPeriodInOneCurrencyCannotBeChangedInAnother(): void
    {
        $this->payWithFake($this->workspace, $this->owner, 'start');
        $this->setPrice('agency', 'USD', 4999);
        $this->enable('stripe', ['secret_key' => 'sk_test_x', 'webhook_secret' => 'whsec_x']);

        $this->expectException(BillingException::class);
        $this->expectExceptionMessage('другой валюте');

        $this->checkout('agency', 'stripe', 'USD');
    }

    public function testAProviderThatDoesNotRenewTurnsOffAutomaticRenewal(): void
    {
        $this->setPrice('pro', 'USD', 1999);
        $this->enable('crypto', ['merchant_id' => 'm', 'api_key' => 'k']);
        $this->http->expect('POST', 'https://api.heleket.com/v1/payment', 200, json_encode(['state' => 0, 'result' => ['uuid' => 'u1', 'payment_status' => 'check', 'amount' => '19.99', 'currency' => 'USD', 'url' => 'https://pay.heleket.com/u1']], JSON_THROW_ON_ERROR));

        $result = $this->billing()->checkout($this->contextFor($this->workspace, $this->owner), $this->owner, 'pro', BillingPeriod::Month, 'crypto', true, 'USD');

        $payment = $this->paymentsRepo()->findByPublicId((string) $result->paymentId) ?? self::fail('no payment');
        self::assertFalse($payment->saveMethod, 'crypto cannot charge later, so the card is not kept');
    }

    public function testThePlansPageOffersCurrenciesAndOnlyTheProvidersThatTakeThem(): void
    {
        $this->setPrice('pro', 'USD', 1999);
        $this->enable('stripe', ['secret_key' => 'sk_test_x', 'webhook_secret' => 'whsec_x']);
        $this->actAs($this->owner);
        $base = '/w/' . $this->workspace->publicId . '/billing/plans';

        $rub = $this->plain($this->get($base));
        $usd = $this->plain($this->get($base . '?currency=USD'));

        self::assertStringContainsString('Тестовая оплата', $rub);
        self::assertStringNotContainsString('Stripe', $rub);
        self::assertStringContainsString('Stripe', $usd);
        self::assertStringNotContainsString('Тестовая оплата', $usd);
        self::assertStringContainsString('$', $usd);
    }

    // ---- promo codes ---------------------------------------------------------------------------------------------

    public function testAPercentCodeLowersTheInvoiceAndIsUsedOncePerPerson(): void
    {
        $this->promos()->create('SPRING50', 'percent', 50, null, [], true, null, null, null, '', null);

        $result = $this->checkout('pro', 'fake', null, ' spring50 ');

        $payment = $this->paymentsRepo()->findByPublicId((string) $result->paymentId) ?? self::fail('no payment');
        $invoice = $this->invoices()->findById($payment->invoiceId) ?? self::fail('no invoice');
        self::assertSame($this->price() - intdiv($this->price(), 2), $invoice->amount);
        self::assertSame(intdiv($this->price(), 2), $invoice->discount);
        self::assertSame($this->price(), $invoice->listPrice);
        self::assertSame(1, $this->promos()->used($invoice->promoCodeId ?? 0));

        $this->app->container()->get(\App\Integrations\Payments\Fake\FakeGateway::class)->answer($payment->publicId, 'pay');
        $this->billing()->sync($payment);

        $this->expectException(BillingException::class);
        $this->expectExceptionMessage('уже использовали');
        $this->checkout('agency', 'fake', null, 'SPRING50');
    }

    public function testAnAbandonedCheckoutGivesTheUseBack(): void
    {
        $promo = $this->promos()->create('ONCE', 'percent', 10, null, [], true, 1, null, null, '', null);
        $this->checkout('pro', 'fake', null, 'ONCE');
        self::assertSame(1, $this->promos()->used($promo->id));

        // Another choice replaces the open invoice; the code is free again.
        $this->checkout('pro', 'fake');

        self::assertSame(0, $this->promos()->used($promo->id));
    }

    public function testCodeRules(): void
    {
        $service = $this->app->container()->get(PromoService::class);
        $plan = $this->plans()->findByCode('pro') ?? self::fail('no plan');
        $other = $this->plans()->findByCode('start') ?? self::fail('no plan');
        $now = $this->clock->now();
        $this->promos()->create('OFF', 'percent', 10, null, [], true, null, null, null, '', null)->id;
        $this->promos()->setActive(($this->promos()->findByCode('OFF') ?? self::fail('no code'))->id, false);
        $this->promos()->create('PASSED', 'percent', 10, null, [], true, null, null, $now->modify('-1 day'), '', null);
        $this->promos()->create('PROONLY', 'percent', 10, null, ['pro'], true, null, null, null, '', null);
        $this->promos()->create('RUBLES', 'amount', 10000, 'RUB', [], true, null, null, null, '', null);
        $this->promos()->create('FULL', 'percent', 10, null, [], true, 1, null, null, '', null);

        $cases = [
            ['nothing', $plan, 'RUB', 'Такого промокода нет'],
            ['OFF', $plan, 'RUB', 'Такого промокода нет'],
            ['PASSED', $plan, 'RUB', 'Срок действия'],
            ['PROONLY', $other, 'RUB', 'не подходит к выбранному тарифу'],
            ['RUBLES', $plan, 'USD', 'другой валюте'],
        ];
        foreach ($cases as [$code, $forPlan, $currency, $message]) {
            try {
                $service->check($code, $this->owner->id, $this->workspace->id, $forPlan, $currency);
                self::fail('Expected a refusal for ' . $code);
            } catch (BillingException $e) {
                self::assertStringContainsString($message, $e->getMessage(), $code);
            }
        }
        // The limit: the second person finds the code used up.
        $service->check('FULL', $this->owner->id, $this->workspace->id, $plan, 'RUB');
        $this->promos()->redeem($this->promos()->findByCode('FULL') ?? self::fail('no code'), $this->workspace->id, $this->owner->id, null, 1);
        $second = $this->createUser('second@example.com');
        $this->expectException(BillingException::class);
        $this->expectExceptionMessage('максимальное число раз');
        $service->check('FULL', $second->id, $this->workspace->id, $plan, 'RUB');
    }

    public function testAFirstPaymentCodeIsRefusedToAWorkspaceThatPaid(): void
    {
        $this->payWithFake($this->workspace, $this->owner, 'start');
        $this->promos()->create('NEWONLY', 'percent', 10, null, [], true, null, null, null, '', null);

        $this->expectException(BillingException::class);
        $this->expectExceptionMessage('только на первую оплату');

        $this->app->container()->get(PromoService::class)->check('NEWONLY', $this->owner->id, $this->workspace->id, $this->plans()->findByCode('pro') ?? self::fail('no plan'), 'RUB');
    }

    public function testADiscountNeverGoesBelowTheSmallestCharge(): void
    {
        $service = $this->app->container()->get(PromoService::class);
        $promo = $this->promos()->create('HUGE', 'amount', 99999999, 'RUB', [], true, null, null, null, '', null);

        self::assertSame(1000 - 100, $service->discount($promo, 1000));
        self::assertSame(0, $service->discount($promo, 100));
    }

    public function testFreeDaysGiveAPlanWithoutPayingAndOnlyOnce(): void
    {
        $this->promos()->create('WEEK', 'days', 7, null, ['pro'], false, null, null, null, '', null);

        $result = $this->checkout('pro', 'fake', null, 'week');

        self::assertNull($result->paymentId);
        $subscription = $this->subscription($this->workspace);
        self::assertSame('pro', $this->plans()->find($subscription->planId)?->code);
        self::assertEquals($this->clock->now()->modify('+7 days'), $subscription->currentPeriodEnd);
        self::assertTrue($subscription->cancelAtPeriodEnd, 'free days do not turn into a charge');
        self::assertSame([], $this->db->select('SELECT 1 FROM invoices WHERE workspace_id = ?', [$this->workspace->id]));

        $this->expectException(BillingException::class);
        $this->checkout('pro', 'fake', null, 'WEEK');
    }

    // ---- add-on packages -----------------------------------------------------------------------------------------

    private function package(string $code, string $kind, int $quantity, int $price, int $days = 30): void
    {
        $this->app->container()->get(AddonRepository::class)->save(null, $code, 'Пакет ' . $code, $kind, $quantity, $days, ['RUB' => $price], true, 0);
    }

    public function testPackagesNeedAPaidPlan(): void
    {
        $this->package('channels_5', 'channels', 5, 29000);

        $this->expectException(BillingException::class);
        $this->expectExceptionMessage('платных тарифах');

        $this->billing()->checkoutAddon($this->contextFor($this->workspace, $this->owner), $this->owner, 'channels_5', 'fake');
    }

    public function testALimitPackageRaisesTheLimitForItsDaysOnly(): void
    {
        $this->package('channels_5', 'channels', 5, 29000, 30);
        $this->payWithFake($this->workspace, $this->owner, 'start');
        $entitlements = $this->app->container()->get(Entitlements::class);
        $before = $entitlements->limit($this->workspace->id, 'channels');
        self::assertNotNull($before);

        $result = $this->billing()->checkoutAddon($this->contextFor($this->workspace, $this->owner), $this->owner, 'channels_5', 'fake');
        $payment = $this->paymentsRepo()->findByPublicId((string) $result->paymentId) ?? self::fail('no payment');
        self::assertSame(29000, $payment->amount);
        $this->app->container()->get(\App\Integrations\Payments\Fake\FakeGateway::class)->answer($payment->publicId, 'pay');
        $this->billing()->sync($payment);

        self::assertSame($before + 5, $entitlements->limit($this->workspace->id, 'channels'));
        $planAfter = $this->subscription($this->workspace);
        self::assertSame('start', $this->plans()->find($planAfter->planId)?->code, 'a package does not change the plan');

        $this->clock->advance(31 * 86400);

        self::assertSame($before, $entitlements->limit($this->workspace->id, 'channels'));
    }

    public function testACreditsPackagePutsCreditsInTheWallet(): void
    {
        $this->package('credits_100', 'ai_credits', 100, 19000);
        $this->payWithFake($this->workspace, $this->owner, 'start');

        $result = $this->billing()->checkoutAddon($this->contextFor($this->workspace, $this->owner), $this->owner, 'credits_100', 'fake');
        $payment = $this->paymentsRepo()->findByPublicId((string) $result->paymentId) ?? self::fail('no payment');
        $this->app->container()->get(\App\Integrations\Payments\Fake\FakeGateway::class)->answer($payment->publicId, 'pay');
        $this->billing()->sync($payment);

        // Reporting the same payment again would not give twice (the second answer of the provider is a no-op).
        $this->billing()->sync($this->paymentsRepo()->findById($payment->id) ?? $payment);
        self::assertSame(100, $this->credits());
    }

    private function credits(): int
    {
        return $this->app->container()->get(Ledger::class)->wallet($this->workspace->publicId)['credits'];
    }

    // ---- Telegram Stars -------------------------------------------------------------------------------------------

    private function startStars(): \App\Domain\Billing\Payment
    {
        $this->setPrice('pro', 'XTR', 25000);
        $this->enable('stars');
        $this->http->expect('POST', 'https://api.telegram.org/bot' . TestEnv::SHARED_BOT_TOKEN . '/createInvoiceLink', 200, json_encode(['ok' => true, 'result' => 'https://t.me/$abc'], JSON_THROW_ON_ERROR));
        $result = $this->checkout('pro', 'stars', 'XTR');
        self::assertSame('https://t.me/$abc', $result->redirectUrl);
        $this->assertStarsAmount(250);

        return $this->paymentsRepo()->findByPublicId((string) $result->paymentId) ?? self::fail('no payment');
    }

    private function assertStarsAmount(int $stars): void
    {
        $last = end($this->http->requests);
        self::assertIsArray($last);
        $sent = $last['options']['json'];
        self::assertSame('XTR', $sent['currency']);
        self::assertSame($stars, $sent['prices'][0]['amount']);
    }

    public function testStarsPreCheckoutAcceptsOurOpenPaymentForTheRightAmountOnly(): void
    {
        $payment = $this->startStars();
        $stars = $this->app->container()->get(StarsPayments::class);

        self::assertTrue($stars->preCheckout(['invoice_payload' => $payment->publicId, 'currency' => 'XTR', 'total_amount' => 250])['ok']);
        self::assertFalse($stars->preCheckout(['invoice_payload' => $payment->publicId, 'currency' => 'XTR', 'total_amount' => 1])['ok'], 'wrong amount');
        self::assertFalse($stars->preCheckout(['invoice_payload' => $payment->publicId, 'currency' => 'USD', 'total_amount' => 250])['ok'], 'wrong currency');
        self::assertFalse($stars->preCheckout(['invoice_payload' => '01JNOSUCHPAYMENT0000000000', 'currency' => 'XTR', 'total_amount' => 250])['ok'], 'no such payment');
        self::assertFalse($stars->preCheckout(['invoice_payload' => 'not-an-id', 'currency' => 'XTR', 'total_amount' => 250])['ok']);
    }

    public function testStarsPreCheckoutRefusesAPaymentOfAnotherProvider(): void
    {
        [, $payment] = $this->payWithFake($this->workspace, $this->owner, 'start');
        $stars = $this->app->container()->get(StarsPayments::class);

        self::assertFalse($stars->preCheckout(['invoice_payload' => $payment->publicId, 'currency' => 'XTR', 'total_amount' => 1])['ok']);
    }

    public function testASuccessfulStarsPaymentSettlesTheInvoiceOnce(): void
    {
        $payment = $this->startStars();
        $stars = $this->app->container()->get(StarsPayments::class);
        $paid = ['invoice_payload' => $payment->publicId, 'currency' => 'XTR', 'total_amount' => 250, 'telegram_payment_charge_id' => 'charge-1'];

        $stars->paid($paid, 777);
        $stars->paid($paid, 777);

        $subscription = $this->subscription($this->workspace);
        self::assertSame('pro', $this->plans()->find($subscription->planId)?->code);
        self::assertSame('XTR', $subscription->currency);
        $row = $this->db->select('SELECT provider_ref, status FROM payments WHERE id = ?', [$payment->id])[0];
        self::assertSame('777:charge-1', $row['provider_ref']);
        self::assertSame('succeeded', $row['status']);
        self::assertSame(1, (int) $this->db->select("SELECT COUNT(*) AS n FROM ledger_entries WHERE ref_type = 'payment' AND ref_id = ?", [$payment->publicId])[0]['n'] / 2);
    }

    public function testAStarsPaymentForTheWrongAmountDoesNothing(): void
    {
        $payment = $this->startStars();

        $this->app->container()->get(StarsPayments::class)->paid(['invoice_payload' => $payment->publicId, 'currency' => 'XTR', 'total_amount' => 1, 'telegram_payment_charge_id' => 'c'], 777);

        self::assertSame('free', $this->plans()->find($this->subscription($this->workspace)->planId)?->code);
    }

    public function testTheBotAnswersAPreCheckoutQueryFromTelegram(): void
    {
        $payment = $this->startStars();
        $this->http->expect('POST', 'https://api.telegram.org/bot' . TestEnv::SHARED_BOT_TOKEN . '/answerPreCheckoutQuery', 200, json_encode(['ok' => true, 'result' => true], JSON_THROW_ON_ERROR));

        $this->app->container()->get(\App\Domain\Channel\TelegramUpdateHandler::class)->handle(['update_id' => 1, 'pre_checkout_query' => ['id' => 'q1', 'from' => ['id' => 777], 'currency' => 'XTR', 'total_amount' => 250, 'invoice_payload' => $payment->publicId]]);

        $last = end($this->http->requests);
        self::assertIsArray($last);
        $sent = $last['options']['json'];
        self::assertSame('q1', $sent['pre_checkout_query_id']);
        self::assertTrue($sent['ok']);
    }

    public function testTheWebhookRouteTakesLongProviderNames(): void
    {
        $this->enable('cloudpayments', ['public_id' => 'pk', 'api_secret' => 'secret']);

        // A call without a valid signature is refused by the provider's own check (403), not lost as an unknown route (404).
        self::assertSame(403, $this->request('POST', '/webhooks/billing/cloudpayments?type=pay', ['TransactionId' => '1', 'InvoiceId' => 'x'])->status);
        self::assertSame(404, $this->request('POST', '/webhooks/billing/paddle', [])->status, 'a provider that is not set up');
    }
}
