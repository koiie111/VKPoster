<?php

declare(strict_types=1);

namespace App\Tests\Feature\Billing;

use App\Domain\Billing\BillingException;
use App\Domain\Billing\BillingPeriod;
use App\Domain\Billing\Referral\ReferralService;
use App\Domain\Billing\Referral\ReferralSettings;
use App\Domain\User\User;
use App\Domain\Workspace\Workspace;
use App\Domain\Workspace\WorkspaceService;
use App\Http\Controllers\ReferralLinkController;
use App\Http\Middleware\AttributeReferral;
use App\Tests\Support\BillingTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The referral programme: the link and its cookie, who is tied to whom, the share of every payment, taking it back on refund, spending the
 * balance, withdrawals, and the checks against inviting oneself.
 */
#[CoversClass(ReferralService::class)]
#[CoversClass(ReferralLinkController::class)]
#[CoversClass(AttributeReferral::class)]
final class ReferralTest extends BillingTestCase
{
    private User $inviter;
    private Workspace $inviterWorkspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->execute('DELETE FROM app_settings');
        [$this->inviter, $this->inviterWorkspace] = $this->ownerWithWorkspace('inviter@example.com', 'Пригласивший');
        $this->givePlan($this->inviterWorkspace, 'free');
    }

    protected function tearDown(): void
    {
        $this->db->execute('DELETE FROM app_settings');
        parent::tearDown();
    }

    private function referrals(): ReferralService
    {
        return $this->app->container()->get(ReferralService::class);
    }

    /**
     * A new person who followed the inviter's link and opened the application from another network address.
     *
     * @return array{User, Workspace}
     */
    private function friend(string $email = 'friend@example.com', string $ip = '198.51.100.7'): array
    {
        $code = $this->referrals()->codeFor($this->inviter->id);
        $user = $this->createUser($email);
        $workspace = $this->app->container()->get(WorkspaceService::class)->createPersonal($user);
        $this->givePlan($workspace, 'free');
        $this->remoteAddr = $ip;
        $this->actAs($user);
        $this->cookies[ReferralService::COOKIE] = $code;
        self::assertLessThan(400, $this->get('/app')->status);

        return [$user, $workspace];
    }

    private function pay(User $user, Workspace $workspace, string $plan = 'pro'): \App\Domain\Billing\Payment
    {
        return $this->payWithFake($workspace, $user, $plan, BillingPeriod::Month, false)[1];
    }

    private function linkStatus(User $user): ?string
    {
        $row = $this->db->select('SELECT status FROM referrals WHERE referred_id = ?', [$user->id])[0] ?? null;

        return $row === null ? null : (string) $row['status'];
    }

    public function testTheLinkRemembersTheInviterAndSendsToRegistration(): void
    {
        $code = $this->referrals()->codeFor($this->inviter->id);

        $response = $this->get('/r/' . $code);

        self::assertSame('/register', $response->header('Location'));
        self::assertSame($code, $this->cookie(ReferralService::COOKIE));
        self::assertSame($code, $this->referrals()->codeFor($this->inviter->id), 'the code is stable');
    }

    public function testAnUnknownCodeLeavesNoCookie(): void
    {
        $response = $this->get('/r/NOSUCHCODE');

        self::assertSame('/register', $response->header('Location'));
        self::assertNull($this->cookie(ReferralService::COOKIE));
    }

    public function testANewAccountIsTiedToTheInviterOnceAndTheCookieIsDropped(): void
    {
        [$friend] = $this->friend();

        self::assertSame('active', $this->linkStatus($friend));
        self::assertNull($this->cookie(ReferralService::COOKIE));
        $this->cookies[ReferralService::COOKIE] = $this->referrals()->codeFor($friend->id);
        $this->get('/app');
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS n FROM referrals WHERE referred_id = ?', [$friend->id])[0]['n'], 'tied once, to the first inviter');
        self::assertSame($this->inviter->id, (int) $this->db->select('SELECT referrer_id FROM referrals WHERE referred_id = ?', [$friend->id])[0]['referrer_id']);
    }

    public function testAnOldAccountCannotBeTiedAfterTheFact(): void
    {
        $code = $this->referrals()->codeFor($this->inviter->id);
        $old = $this->createUser('old@example.com');
        $this->clock->advance(10 * 86400);
        $this->remoteAddr = '198.51.100.9';
        $this->actAs($old);
        $this->cookies[ReferralService::COOKIE] = $code;

        $this->get('/app');

        self::assertNull($this->linkStatus($old));
    }

    public function testOwnLinkAndTheSameNetworkAddressDoNotPay(): void
    {
        $this->actAs($this->inviter);
        $this->cookies[ReferralService::COOKIE] = $this->referrals()->codeFor($this->inviter->id);
        $this->get('/app');
        self::assertSame('rejected', $this->linkStatus($this->inviter));

        // The inviter has been signed in from 203.0.113.10; a "friend" who comes from the same address is held back.
        [$friend, $workspace] = $this->friend('same@example.com', '203.0.113.10');
        self::assertSame('flagged', $this->linkStatus($friend));
        $this->pay($friend, $workspace);
        self::assertSame(0, $this->referrals()->balance($this->inviter->id));
    }

    public function testTheInviterGetsTheShareOfEveryPaymentAndOnlyOnce(): void
    {
        [$friend, $workspace] = $this->friend();

        $payment = $this->pay($friend, $workspace);

        self::assertSame(intdiv($payment->amount * 20, 100), $this->referrals()->balance($this->inviter->id));
        $this->billing()->sync($this->paymentsRepo()->findById($payment->id) ?? $payment);
        self::assertSame(intdiv($payment->amount * 20, 100), $this->referrals()->balance($this->inviter->id), 'a second report of the same payment pays nothing');
        $summary = $this->referrals()->summary($this->inviter->id);
        self::assertSame(1, $summary['people']);
        self::assertSame(intdiv($payment->amount * 20, 100), $summary['earned']);
    }

    public function testTheShareIsTheOwnersSetting(): void
    {
        $this->app->container()->get(ReferralSettings::class)->save(true, '10', '30', '500', null);
        [$friend, $workspace] = $this->friend();

        $payment = $this->pay($friend, $workspace);

        self::assertSame(intdiv($payment->amount * 10, 100), $this->referrals()->balance($this->inviter->id));
    }

    public function testASwitchedOffProgrammeTiesAndPaysNobody(): void
    {
        $this->app->container()->get(ReferralSettings::class)->save(false, '20', '30', '500', null);
        [$friend, $workspace] = $this->friend();

        self::assertNull($this->linkStatus($friend));
        $this->pay($friend, $workspace);
        self::assertSame(0, $this->referrals()->balance($this->inviter->id));
    }

    public function testARefundTakesTheBonusBackInProportion(): void
    {
        [$friend, $workspace] = $this->friend();
        $payment = $this->pay($friend, $workspace);
        $bonus = $this->referrals()->balance($this->inviter->id);

        $this->billing()->refund($payment, intdiv($payment->amount, 2), null);
        self::assertSame($bonus - intdiv($bonus, 2), $this->referrals()->balance($this->inviter->id));

        $payment = $this->paymentsRepo()->findById($payment->id) ?? $payment;
        $this->billing()->refund($payment, $payment->amount - $payment->refundedAmount, null);
        self::assertSame(0, $this->referrals()->balance($this->inviter->id));
    }

    public function testTheBalancePaysForAPlanWithoutAnOutsideProviderAndEarnsNothingForAnyone(): void
    {
        [$friend, $workspace] = $this->friend();
        $this->pay($friend, $workspace, 'agency');
        $balance = $this->referrals()->balance($this->inviter->id);
        $price = $this->plans()->findByCode('start')?->priceFor(BillingPeriod::Month) ?? self::fail('no price');
        self::assertGreaterThanOrEqual($price, $balance);

        $result = $this->billing()->checkout($this->contextFor($this->inviterWorkspace, $this->inviter), $this->inviter, 'start', BillingPeriod::Month, 'balance', false);

        self::assertNull($result->paymentId);
        self::assertSame($balance - $price, $this->referrals()->balance($this->inviter->id));
        self::assertSame('start', $this->plans()->find($this->subscription($this->inviterWorkspace)->planId)?->code);
    }

    public function testTheBalanceCannotPayMoreThanItHolds(): void
    {
        $this->expectException(BillingException::class);
        $this->expectExceptionMessage('не хватает');

        $this->billing()->checkout($this->contextFor($this->inviterWorkspace, $this->inviter), $this->inviter, 'pro', BillingPeriod::Month, 'balance', false);
    }

    public function testWithdrawalTakesTheMoneyAtOnceAndARejectionReturnsIt(): void
    {
        [$friend, $workspace] = $this->friend();
        $this->pay($friend, $workspace, 'agency');
        $balance = $this->referrals()->balance($this->inviter->id);
        $staff = $this->createUser('staff-payout@example.com');

        try {
            $this->referrals()->requestPayout($this->inviter, 100, 'карта 1234');
            self::fail('Below the minimum.');
        } catch (BillingException $e) {
            self::assertStringContainsString('Минимальная', $e->getMessage());
        }
        try {
            $this->referrals()->requestPayout($this->inviter, $balance + 1, 'карта 1234 Иван');
            self::fail('More than the balance.');
        } catch (BillingException $e) {
            self::assertStringContainsString('меньше', $e->getMessage());
        }

        $id = $this->referrals()->requestPayout($this->inviter, 50000, 'карта 2200 0000 0000 0000, Иван И.');
        self::assertSame($balance - 50000, $this->referrals()->balance($this->inviter->id));
        $stored = (string) $this->db->select('SELECT details FROM referral_payouts WHERE public_id = ?', [$id])[0]['details'];
        self::assertStringNotContainsString('2200', $stored, 'where to send the money is stored encrypted');
        self::assertSame('карта 2200 0000 0000 0000, Иван И.', $this->referrals()->payouts('requested')[0]['details']);

        $this->referrals()->handlePayout($id, false, 'не прошёл перевод', $staff->id);
        self::assertSame('', (string) $this->db->select('SELECT details FROM referral_payouts WHERE public_id = ?', [$id])[0]['details'], 'wiped once handled');
        self::assertSame($balance, $this->referrals()->balance($this->inviter->id));

        try {
            $this->referrals()->handlePayout($id, true, '', $staff->id);
            self::fail('Handled twice.');
        } catch (BillingException) {
            $this->addToAssertionCount(1);
        }

        $second = $this->referrals()->requestPayout($this->inviter, 50000, 'карта 2200 0000 0000 0000, Иван И.');
        $this->referrals()->handlePayout($second, true, 'выплачено', $staff->id);
        self::assertSame($balance - 50000, $this->referrals()->balance($this->inviter->id));
    }

    public function testTheAccountPageShowsTheLinkAndTheBalance(): void
    {
        $this->actAs($this->inviter);

        $page = $this->plain($this->get('/account/referrals'));

        self::assertStringContainsString('/r/' . $this->referrals()->codeFor($this->inviter->id), $page);
        self::assertStringContainsString('20%', $page);
    }

    public function testTheSameSavedCardOnBothSidesHoldsTheBonusBack(): void
    {
        $this->payWithFake($this->inviterWorkspace, $this->inviter, 'start', BillingPeriod::Month, true);
        [$friend, $workspace] = $this->friend();

        $this->payWithFake($workspace, $friend, 'pro', BillingPeriod::Month, true);

        self::assertSame('flagged', $this->linkStatus($friend));
        self::assertSame(0, $this->referrals()->balance($this->inviter->id));
        $this->referrals()->decide((int) $this->db->select('SELECT id FROM referrals WHERE referred_id = ?', [$friend->id])[0]['id'], true, $this->inviter->id);
        self::assertSame('active', $this->linkStatus($friend));
    }
}
