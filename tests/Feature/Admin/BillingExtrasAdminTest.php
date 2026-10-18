<?php

declare(strict_types=1);

namespace App\Tests\Feature\Admin;

use App\Domain\Admin\StaffRole;
use App\Domain\Billing\AddonRepository;
use App\Domain\Billing\PromoRepository;
use App\Domain\Billing\Referral\ReferralService;
use App\Domain\Billing\Referral\ReferralSettings;
use App\Http\Controllers\Admin\AddonsAdminController;
use App\Http\Controllers\Admin\PromoAdminController;
use App\Http\Controllers\Admin\ReferralAdminController;
use App\Tests\Support\AdminTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The owner's screens for promo codes, add-on packages, the referral programme and the multi-currency price list.
 */
#[CoversClass(PromoAdminController::class)]
#[CoversClass(AddonsAdminController::class)]
#[CoversClass(ReferralAdminController::class)]
final class BillingExtrasAdminTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->db->execute('DELETE FROM promo_codes');
        $this->db->execute('DELETE FROM addon_packages');
    }

    protected function tearDown(): void
    {
        $this->db->execute('DELETE FROM promo_codes');
        $this->db->execute('DELETE FROM addon_packages');
        $this->db->execute('DELETE FROM plan_prices WHERE currency <> \'RUB\'');
        parent::tearDown();
    }

    public function testOnlyMoneyStaffOpenTheScreens(): void
    {
        $this->staff(StaffRole::Content);
        foreach (['/admin/promo', '/admin/addons', '/admin/referrals'] as $url) {
            self::assertSame(403, $this->get($url)->status, $url);
        }
        $this->staff(StaffRole::Finance);
        foreach (['/admin/promo', '/admin/addons', '/admin/referrals'] as $url) {
            self::assertSame(200, $this->get($url)->status, $url);
        }
    }

    public function testAPromoCodeIsCreatedCheckedAndSwitchedOff(): void
    {
        $this->staff(StaffRole::Finance);

        $this->post('/admin/promo', ['code' => 'bad code', 'kind' => 'percent', 'value' => '10']);
        $this->post('/admin/promo', ['code' => 'SPRING', 'kind' => 'percent', 'value' => '150']);
        $this->post('/admin/promo', ['code' => 'WEEK', 'kind' => 'days', 'value' => '7']);
        self::assertSame([], $this->db->select('SELECT 1 FROM promo_codes'), 'every bad form is refused whole');

        $this->post('/admin/promo', ['code' => 'spring25', 'kind' => 'percent', 'value' => '25', 'plans' => ['pro'], 'max' => '100', 'from' => '2026-01-01', 'until' => '2026-12-31', 'first_payment_only' => '1']);
        $promo = $this->app->container()->get(PromoRepository::class)->findByCode('SPRING25');
        self::assertNotNull($promo);
        self::assertSame(25, $promo->value);
        self::assertSame(['pro'], $promo->planCodes);
        self::assertSame(100, $promo->maxRedemptions);
        self::assertTrue($promo->firstPaymentOnly);
        self::assertStringContainsString('SPRING25', $this->get('/admin/promo')->body);

        $this->post('/admin/promo', ['code' => 'SPRING25', 'kind' => 'percent', 'value' => '30']);
        self::assertSame(25, ($this->app->container()->get(PromoRepository::class)->findByCode('SPRING25') ?? self::fail('gone'))->value, 'a code is not overwritten');

        $this->post('/admin/promo/' . $promo->id . '/toggle', []);
        self::assertFalse($this->app->container()->get(PromoRepository::class)->findByCode('SPRING25')?->active);
        self::assertContains('admin.promo_created', $this->adminActions());
    }

    public function testFreeDaysNeedExactlyOnePlan(): void
    {
        $this->staff(StaffRole::Finance);

        $this->post('/admin/promo', ['code' => 'WEEK', 'kind' => 'days', 'value' => '7', 'plans' => ['pro', 'start']]);
        self::assertSame([], $this->db->select('SELECT 1 FROM promo_codes'));

        $this->post('/admin/promo', ['code' => 'WEEK', 'kind' => 'days', 'value' => '7', 'plans' => ['pro']]);
        self::assertSame('days', $this->app->container()->get(PromoRepository::class)->findByCode('WEEK')?->kind);
    }

    public function testAPackageIsSavedWithPricesInSeveralCurrencies(): void
    {
        $this->staff(StaffRole::Superadmin);

        $this->post('/admin/addons', ['code' => 'space_10', 'name' => '10 ГБ', 'kind' => 'storage_bytes', 'quantity' => '10240', 'days' => '30', 'price_rub' => '490', 'price_usd' => '5.5', 'price_xtr' => '300', 'active' => '1']);

        $package = $this->app->container()->get(AddonRepository::class)->findByCode('space_10');
        self::assertNotNull($package);
        self::assertSame(10240 * 1024 * 1024, $package->quantity, 'space is typed in megabytes');
        self::assertSame(['RUB' => 49000, 'USD' => 550, 'XTR' => 30000], $package->prices);
        self::assertTrue($package->active);

        $this->post('/admin/addons', ['code' => 'space_10', 'name' => '10 ГБ', 'kind' => 'storage_bytes', 'quantity' => '10240', 'days' => '30', 'price_rub' => '590']);
        $changed = $this->app->container()->get(AddonRepository::class)->findByCode('space_10');
        self::assertNotNull($changed);
        self::assertSame(['RUB' => 59000], $changed->prices);
        self::assertFalse($changed->active, 'an unchecked box switches it off');
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS n FROM addon_packages')[0]['n'], 'the same code updates');
    }

    public function testBadPackagesAreRefused(): void
    {
        $this->staff(StaffRole::Superadmin);

        $this->post('/admin/addons', ['code' => 'X', 'name' => 'a', 'kind' => '', 'quantity' => '0']);
        $this->post('/admin/addons', ['code' => 'stars_pack', 'name' => 'Звёзды', 'kind' => 'ai_credits', 'quantity' => '50', 'price_xtr' => '1.5']);

        self::assertSame([], $this->db->select('SELECT 1 FROM addon_packages'));
    }

    public function testThePriceListTakesDollarsEurosAndWholeStars(): void
    {
        $this->staff(StaffRole::Finance);

        $this->post('/admin/plans/pro', $this->confirm(['name' => 'Про', 'is_public' => '1', 'price_month' => '990', 'price_year' => '9900', 'price_month_usd' => '12.5', 'price_month_eur' => '11', 'price_month_xtr' => '700', 'limit_channels' => '10']));

        $plan = $this->app->container()->get(\App\Domain\Billing\PlanRepository::class)->findByCode('pro');
        self::assertNotNull($plan);
        self::assertSame(1250, $plan->prices['month']['USD'] ?? null);
        self::assertSame(1100, $plan->prices['month']['EUR'] ?? null);
        self::assertSame(70000, $plan->prices['month']['XTR'] ?? null);
        self::assertSame(99000, $plan->prices['month']['RUB'] ?? null);

        $this->post('/admin/plans/pro', $this->confirm(['name' => 'Про', 'is_public' => '1', 'price_month' => '990', 'price_month_xtr' => '7.5', 'limit_channels' => '10']));
        self::assertSame(70000, $this->app->container()->get(\App\Domain\Billing\PlanRepository::class)->findByCode('pro')?->prices['month']['XTR'] ?? null, 'stars are whole numbers: the bad form changed nothing');
    }

    public function testReferralTermsNeedACodeAndAreValidated(): void
    {
        $this->staff(StaffRole::Finance);
        $settings = $this->app->container()->get(ReferralSettings::class);
        $form = ['enabled' => '1', 'percent' => '15', 'cookie_days' => '45', 'min_payout' => '700'];

        $this->post('/admin/referrals/settings', $form);
        self::assertSame(20, $settings->percent(), 'no code, nothing changed');

        $this->post('/admin/referrals/settings', $this->confirm($form));
        self::assertSame(15, $settings->percent());
        self::assertSame(45, $settings->cookieDays());
        self::assertSame(70000, $settings->minPayout());

        $this->post('/admin/referrals/settings', $this->confirm(['percent' => '95', 'cookie_days' => '0', 'min_payout' => 'x']));
        self::assertSame(15, $settings->percent());
    }

    public function testStaffHandleAWithdrawalAfterTheCode(): void
    {
        $staff = $this->staff(StaffRole::Finance);
        $user = $this->createUser('partner@example.com');
        $referrals = $this->app->container()->get(ReferralService::class);
        $ledger = $this->app->container()->get(\App\Domain\Billing\Ledger::class);
        $ledger->post('test', 'seed-' . $user->id, 'seed', 'RUB', [
            ['code' => 'referral:expense', 'name' => 'x', 'kind' => 'expense', 'amount' => 100000],
            ['code' => ReferralService::account($user->id), 'name' => 'x', 'kind' => 'liability', 'amount' => -100000],
        ]);
        $id = $referrals->requestPayout($user, 60000, 'карта 2200 1111 2222 3333');
        self::assertStringContainsString('карта 2200', $this->get('/admin/referrals')->body);

        $this->post('/admin/referrals/payouts/' . $id . '/paid', ['note' => 'ок']);
        self::assertSame('requested', (string) $this->db->select('SELECT status FROM referral_payouts WHERE public_id = ?', [$id])[0]['status'], 'no code, nothing happened');

        $this->post('/admin/referrals/payouts/' . $id . '/paid', $this->confirm(['note' => 'выплачено']));
        self::assertSame('paid', (string) $this->db->select('SELECT status FROM referral_payouts WHERE public_id = ?', [$id])[0]['status']);
        self::assertSame($staff->id, (int) $this->db->select('SELECT handled_by FROM referral_payouts WHERE public_id = ?', [$id])[0]['handled_by']);
    }
}
