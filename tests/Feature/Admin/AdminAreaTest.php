<?php

declare(strict_types=1);

namespace App\Tests\Feature\Admin;

use App\Domain\Admin\AdminDirectory;
use App\Domain\Admin\FailedJobs;
use App\Domain\Billing\BillingPeriod;
use App\Domain\Billing\PlanEditor;
use App\Domain\User\User;
use App\Domain\User\UserRepository;
use App\Domain\Workspace\Workspace;
use App\Http\Auth\Impersonation;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\BillingAdminController;
use App\Http\Controllers\Admin\OperationsController;
use App\Http\Controllers\Admin\UsersController;
use App\Http\Controllers\Admin\WorkspacesController;
use App\Http\Middleware\DenyWhenImpersonating;
use App\Http\Middleware\RequireAdminUnlock;
use App\Http\Middleware\RequireStaff;
use App\Tests\Support\AdminTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The back office: who may enter (superadmin + two-factor + fresh code), what staff can do to users, workspaces, money, queues and
 * networks, and how "sign in as" is fenced and recorded.
 */
#[CoversClass(RequireStaff::class)]
#[CoversClass(RequireAdminUnlock::class)]
#[CoversClass(DenyWhenImpersonating::class)]
#[CoversClass(Impersonation::class)]
#[CoversClass(AdminController::class)]
#[CoversClass(UsersController::class)]
#[CoversClass(WorkspacesController::class)]
#[CoversClass(BillingAdminController::class)]
#[CoversClass(OperationsController::class)]
#[CoversClass(AdminDirectory::class)]
#[CoversClass(FailedJobs::class)]
#[CoversClass(PlanEditor::class)]
final class AdminAreaTest extends AdminTestCase
{
    public function testGuestsAndOrdinaryUsersDoNotSeeTheAdminArea(): void
    {
        $this->useBrowser();
        self::assertSame('/login', $this->get('/admin')->header('Location'));

        [$user] = $this->ownerWithWorkspace();
        $this->actAs($user);
        foreach (['/admin', '/admin/users', '/admin/unlock', '/admin/plans'] as $path) {
            self::assertSame(404, $this->get($path)->status, $path);
        }
        self::assertSame(404, $this->post('/admin/platforms', [])->status);
    }

    public function testStaffWithoutTwoFactorAreAskedToSwitchItOn(): void
    {
        $this->staff(unlock: false, twoFactor: false);
        $page = $this->get('/admin');
        self::assertSame(403, $page->status);
        self::assertStringContainsString('Сначала включите двухфакторную защиту', $page->body);
    }

    public function testStaffNeedAFreshCodeBeforeTheAdminPagesOpen(): void
    {
        $staff = $this->staff(unlock: false);
        $first = $this->get('/admin/users');
        self::assertSame('/admin/unlock', $first->header('Location'));
        self::assertSame(200, $this->get('/admin/unlock')->status);

        $wrong = $this->post('/admin/unlock', ['code' => '000000']);
        self::assertSame('/admin/unlock', $wrong->header('Location'));
        self::assertStringContainsString('Код не подошёл', $this->get('/admin/unlock')->body);

        $right = $this->post('/admin/unlock', ['code' => $this->totpCode($staff->id)]);
        self::assertSame('/admin/users', $right->header('Location'));
        self::assertSame(200, $this->get('/admin')->status);

        // An old or missing unlock does not count (the session itself ends sooner than the unlock in practice).
        self::assertFalse(RequireAdminUnlock::isUnlocked(null, $this->clock));
    }

    public function testOverviewAndListsRender(): void
    {
        $this->staff();
        [, $workspace] = $this->ownerWithWorkspace('owner2@example.com');
        $overview = $this->get('/admin');
        self::assertSame(200, $overview->status);
        self::assertStringContainsString('Обзор', $overview->body);
        // The legal templates still have blanks, which the overview points out.
        self::assertStringContainsString('Юридические документы ещё не заполнены', $overview->body);
        foreach (['/admin/users', '/admin/workspaces', '/admin/subscriptions', '/admin/payments', '/admin/plans', '/admin/promo', '/admin/queues', '/admin/channels', '/admin/platforms', '/admin/workspaces/' . $workspace->publicId] as $path) {
            self::assertSame(200, $this->get($path)->status, $path);
        }
        self::assertSame(404, $this->get('/admin/workspaces/01JZZZZZZZZZZZZZZZZZZZZZZZ')->status);
    }

    public function testUserSearchFindsByEmailNameAndNumberAndEscapesWildcards(): void
    {
        $this->staff();
        $anna = $this->createUser('anna.search@example.com', true, null, 'Анна Поиск');
        $this->createUser('other@example.com', true, null, 'Другой');

        self::assertStringContainsString('anna.search@example.com', $this->get('/admin/users?q=anna.search')->body);
        self::assertStringNotContainsString('other@example.com', $this->get('/admin/users?q=anna.search')->body);
        self::assertStringContainsString('anna.search@example.com', $this->get('/admin/users?q=' . rawurlencode('Поиск'))->body);
        self::assertStringContainsString('anna.search@example.com', $this->get('/admin/users?q=' . $anna->id)->body);
        // `%` is a plain character in a search, not "everything".
        self::assertStringContainsString('Никого не нашли', $this->get('/admin/users?q=%25')->body);
    }

    public function testBlockingEndsSessionsAndIsAudited(): void
    {
        $staff = $this->staff();
        $victim = $this->createUser('victim@example.com', true, null, 'Жертва');
        $this->app->container()->get(\App\Domain\Auth\SessionRegistry::class)->register($victim->id, 'victim-session-id', '203.0.113.5', 'ua');
        self::assertSame($victim->id, $this->app->container()->get(\App\Domain\Auth\SessionRegistry::class)->activeUserId('victim-session-id'));

        $response = $this->post('/admin/users/' . $victim->id . '/block', $this->confirm(['reason' => 'Спам']));
        self::assertSame('/admin/users/' . $victim->id, $response->header('Location'));
        $row = $this->db->select('SELECT status FROM users WHERE id = ?', [$victim->id])[0];
        self::assertSame('blocked', $row['status']);
        self::assertNull($this->app->container()->get(\App\Domain\Auth\SessionRegistry::class)->activeUserId('victim-session-id'));
        self::assertNotSame([], $this->db->select("SELECT 1 FROM audit_log WHERE action = 'admin.user_blocked' AND actor_id = ?", [$staff->id]));

        $this->post('/admin/users/' . $victim->id . '/unblock');
        self::assertSame('active', $this->db->select('SELECT status FROM users WHERE id = ?', [$victim->id])[0]['status']);
    }

    public function testStaffCannotBlockThemselvesOrEachOther(): void
    {
        $staff = $this->staff();
        $other = $this->createUser('boss2@example.com');
        $this->app->container()->get(UserRepository::class)->setSuperadmin($other->id, true);

        $this->post('/admin/users/' . $staff->id . '/block', $this->confirm(['reason' => 'Спам']));
        $this->post('/admin/users/' . $other->id . '/block', $this->confirm(['reason' => 'Спам']));
        self::assertSame(['active', 'active'], array_column($this->db->select('SELECT status FROM users WHERE id IN (?, ?) ORDER BY id', [$staff->id, $other->id]), 'status'));
    }

    public function testImpersonationIsFencedRecordedAndEnds(): void
    {
        $staff = $this->staff();
        [$owner, $workspace] = $this->ownerWithWorkspace('customer@example.com', 'Клиент');

        $start = $this->post('/admin/users/' . $owner->id . '/impersonate', $this->confirm());
        self::assertSame('/app', $start->header('Location'));
        $home = $this->getApp();
        self::assertSame(200, $home->status);
        self::assertStringContainsString('Вы вошли как Клиент', $home->body);

        // The customer's billing and account security are closed, and so are the admin pages.
        self::assertSame(403, $this->get('/w/' . $workspace->publicId . '/billing')->status);
        self::assertSame(403, $this->get('/account/security')->status);
        self::assertSame(403, $this->post('/account/password', ['password' => 'x'])->status);
        self::assertSame(403, $this->post('/logout/all')->status);
        self::assertSame(404, $this->get('/admin')->status);
        self::assertSame(403, $this->post('/w/' . $workspace->publicId . '/delete', ['confirm_name' => $workspace->name])->status);

        // What the "customer" does is tagged with the staff member.
        $this->post('/w/' . $workspace->publicId . '/settings', ['name' => 'Новое имя', 'timezone' => 'Europe/Moscow', 'locale' => 'ru']);
        $tagged = $this->db->select("SELECT meta_json FROM audit_log WHERE action = 'workspace.updated'");
        self::assertNotSame([], $tagged);
        self::assertMatchesRegularExpression('/"impersonator": ?' . $staff->id . '\b/', (string) $tagged[0]['meta_json']);

        $stop = $this->post('/impersonation/stop');
        self::assertSame('/admin/users', $stop->header('Location'));
        self::assertSame(200, $this->get('/admin')->status);
        self::assertStringNotContainsString('Вы вошли как', $this->get('/admin')->body);
        $actions = array_column($this->db->select("SELECT action FROM audit_log WHERE action LIKE 'admin.impersonation%' ORDER BY id"), 'action');
        self::assertSame(['admin.impersonation_started', 'admin.impersonation_stopped'], $actions);
    }

    public function testImpersonationExpiresAndStopsIfStaffLoseTheirRights(): void
    {
        $staff = $this->staff();
        [$owner] = $this->ownerWithWorkspace('customer@example.com', 'Клиент');
        $this->post('/admin/users/' . $owner->id . '/impersonate', $this->confirm());
        self::assertSame(200, $this->getApp()->status);

        $this->clock->advance(Impersonation::TTL + 5);
        self::assertStringNotContainsString('Вы вошли как', $this->getApp()->body);
        // Back as the staff member, whose admin lock closed after the idle hour.
        self::assertSame('/admin/unlock', $this->get('/admin')->header('Location'));
        $this->unlockAdmin($staff);

        $this->post('/admin/users/' . $owner->id . '/impersonate', $this->confirm());
        $this->app->container()->get(UserRepository::class)->setSuperadmin($staff->id, false);
        self::assertSame('/login', $this->get('/app')->header('Location'));
    }

    public function testImpersonatingStaffOrBlockedPeopleIsRefused(): void
    {
        $staff = $this->staff();
        $other = $this->createUser('boss2@example.com');
        $this->app->container()->get(UserRepository::class)->setSuperadmin($other->id, true);
        $this->post('/admin/users/' . $other->id . '/impersonate', $this->confirm());
        self::assertSame(200, $this->get('/admin')->status, 'still the staff member');

        $victim = $this->createUser('victim@example.com');
        $this->app->container()->get(UserRepository::class)->setStatus($victim->id, 'blocked');
        $this->post('/admin/users/' . $victim->id . '/impersonate', $this->confirm());
        self::assertSame(200, $this->get('/admin')->status);
        self::assertSame([], $this->db->select("SELECT 1 FROM audit_log WHERE action = 'admin.impersonation_started' AND actor_id = ?", [$staff->id]));
    }

    public function testGrantingAPlanAndReadingSubscriptions(): void
    {
        $staff = $this->staff();
        [, $workspace] = $this->ownerWithWorkspace('customer@example.com');

        $response = $this->post('/admin/workspaces/' . $workspace->publicId . '/grant', $this->confirm(['plan' => 'agency', 'period' => 'year']));
        self::assertSame('/admin/workspaces/' . $workspace->publicId, $response->header('Location'));
        self::assertSame('Агентство', $this->subscriptionPlanName($workspace));
        self::assertNotSame([], $this->db->select("SELECT 1 FROM audit_log WHERE action = 'billing.plan_granted' AND actor_id = ?", [$staff->id]));
        self::assertStringContainsString('Агентство', $this->get('/admin/subscriptions?status=active')->body);

        $this->post('/admin/workspaces/' . $workspace->publicId . '/grant', $this->confirm(['plan' => 'nope', 'period' => 'month']));
        self::assertSame('Агентство', $this->subscriptionPlanName($workspace));
    }

    private function subscriptionPlanName(Workspace $workspace): string
    {
        return (string) $this->db->select('SELECT p.name FROM subscriptions s JOIN plans p ON p.id = s.plan_id WHERE s.workspace_id = ?', [$workspace->id])[0]['name'];
    }

    public function testRefundNeedsConfirmationAndMovesMoneyBack(): void
    {
        $this->staff();
        [$owner, $workspace] = $this->ownerWithWorkspace('payer@example.com');
        [, $payment] = $this->payWithFake($workspace, $owner, 'pro');

        self::assertStringContainsString('payer@example.com', $this->get('/admin/payments?status=succeeded')->body);
        $this->post('/admin/payments/' . $payment->publicId . '/refund', $this->confirm(['amount' => '100']));
        self::assertSame(0, (int) $this->db->select('SELECT refunded_amount FROM payments WHERE public_id = ?', [$payment->publicId])[0]['refunded_amount']);

        $this->post('/admin/payments/' . $payment->publicId . '/refund', $this->confirm(['amount' => '100,50', 'confirm' => '1']));
        self::assertSame(10050, (int) $this->db->select('SELECT refunded_amount FROM payments WHERE public_id = ?', [$payment->publicId])[0]['refunded_amount']);

        $this->post('/admin/payments/' . $payment->publicId . '/refund', $this->confirm(['confirm' => '1']));
        self::assertSame('refunded', $this->db->select('SELECT status FROM payments WHERE public_id = ?', [$payment->publicId])[0]['status']);
        self::assertSame(404, $this->post('/admin/payments/01JZZZZZZZZZZZZZZZZZZZZZZZ/refund', $this->confirm(['confirm' => '1']))->status);
    }

    public function testPlanPricesCanBeChangedAndTheLandingFollows(): void
    {
        $this->staff();
        self::assertStringContainsString('Тариф «Про»', $this->text($this->get('/admin/plans/pro')));

        $bad = $this->post('/admin/plans/pro', $this->confirm(['name' => 'Про', 'price_month' => 'много', 'price_year' => '', 'limit_channels' => '30']));
        self::assertSame('/admin/plans/pro', $bad->header('Location'));
        self::assertStringContainsString('положительным числом', $this->get('/admin/plans/pro')->body);

        $body = ['name' => 'Про', 'is_public' => '1', 'price_month' => '1 111', 'price_year' => '11111,5', 'limit_channels' => '33', 'limit_posts_per_month' => '', 'limit_storage_bytes' => '2048', 'feature_api' => '1'];
        $ok = $this->post('/admin/plans/pro', $this->confirm($body));
        self::assertSame('/admin/plans', $ok->header('Location'));
        $plan = $this->plans()->findByCode('pro');
        self::assertNotNull($plan);
        self::assertSame(111100, $plan->priceFor(BillingPeriod::Month));
        self::assertSame(1111150, $plan->priceFor(BillingPeriod::Year));
        self::assertSame(33, $plan->limit('channels'));
        self::assertNull($plan->limit('posts_per_month'));
        self::assertSame(2048 * 1024 * 1024, $plan->limit('storage_bytes'));
        self::assertTrue($plan->hasFeature('api'));
        self::assertFalse($plan->hasFeature('slots'));
        self::assertStringContainsString('1' . "\u{00A0}" . '111' . "\u{00A0}" . '₽', $this->get('/')->body);
        self::assertNotSame([], $this->db->select("SELECT 1 FROM audit_log WHERE action = 'admin.plan_updated'"));

    }

    public function testFreePlanCannotGetAPriceAndPaidPlanNeedsOne(): void
    {
        $this->staff();
        $this->post('/admin/plans/free', $this->confirm(['name' => 'Free', 'price_month' => '100', 'limit_channels' => '2', 'limit_posts_per_month' => '30']));
        self::assertNull($this->plans()->free()->priceFor(BillingPeriod::Month));

        $this->post('/admin/plans/start', $this->confirm(['name' => 'Старт', 'price_month' => '', 'price_year' => '']));
        self::assertNotNull($this->plans()->findByCode('start')?->priceFor(BillingPeriod::Month));
    }

    public function testFailedJobsCanBeRetriedOrDiscarded(): void
    {
        $this->staff();
        $payload = json_encode(['class' => 'App\\Domain\\Notification\\SendMailJob', 'data' => []], JSON_THROW_ON_ERROR);
        $now = gmdate('Y-m-d H:i:s.u');
        $a = (int) $this->db->table('failed_jobs')->insert(['queue' => 'default', 'payload_json' => $payload, 'attempts' => 3, 'error' => 'Connection refused', 'failed_at' => $now]);
        $b = (int) $this->db->table('failed_jobs')->insert(['queue' => 'default', 'payload_json' => $payload, 'attempts' => 3, 'error' => 'Timeout', 'failed_at' => $now]);

        $page = $this->get('/admin/queues');
        self::assertStringContainsString('SendMailJob', $page->body);
        self::assertStringContainsString('Connection refused', $page->body);
        self::assertStringNotContainsString('"class"', $page->body, 'the payload is never shown');

        $this->post('/admin/queues/failed/' . $a . '/retry');
        self::assertSame([], $this->db->select('SELECT 1 FROM failed_jobs WHERE id = ?', [$a]));
        $job = $this->db->select('SELECT queue, attempts FROM jobs');
        self::assertCount(1, $job);
        self::assertSame('default', $job[0]['queue']);

        $this->post('/admin/queues/failed/' . $b . '/discard');
        self::assertSame([], $this->db->select('SELECT 1 FROM failed_jobs'));
        // Doing it twice is harmless.
        self::assertSame(302, $this->post('/admin/queues/failed/' . $b . '/retry')->status);
    }

    public function testPlatformSwitchAndNoticeAreSavedAndAudited(): void
    {
        $staff = $this->staff();
        $this->post('/admin/platforms', ['on_telegram' => '1', 'notice_telegram' => 'Telegram чинится', 'on_vk' => '1', 'notice_vk' => '']);
        $page = $this->get('/admin/platforms');
        self::assertStringContainsString('Telegram чинится', $page->body);
        $registry = $this->app->container()->get(\App\Integrations\Social\PlatformRegistry::class);
        self::assertFalse($registry->isEnabled(\App\Integrations\Social\Contracts\Platform::Max), 'MAX was left unchecked');
        self::assertTrue($registry->isEnabled(\App\Integrations\Social\Contracts\Platform::Telegram));
        self::assertNotSame([], $this->db->select("SELECT 1 FROM audit_log WHERE action = 'admin.platforms_changed' AND actor_id = ?", [$staff->id]));

        $this->post('/admin/platforms', ['on_telegram' => '1', 'on_vk' => '1', 'on_max' => '1']);
        self::assertTrue($registry->isEnabled(\App\Integrations\Social\Contracts\Platform::Max));
    }
}
