<?php

declare(strict_types=1);

namespace App\Tests\Feature\Admin;

use App\Domain\Admin\AdminAuditReader;
use App\Domain\Admin\StaffAccess;
use App\Domain\Admin\StaffRole;
use App\Http\Admin\AdminInput;
use App\Http\Admin\AdminNav;
use App\Http\Admin\StepUp;
use App\Http\Controllers\Admin\AdminAuditController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Middleware\AdminAuditTrail;
use App\Http\Middleware\RequireAdminUnlock;
use App\Http\Middleware\RequireStaff;
use App\Http\Middleware\RequireStaffPermission;
use App\Kernel\Http\Route;
use App\Kernel\Http\Router;
use App\Support\Csv;
use App\Tests\Support\AdminTestCase;
use App\Tests\Support\FakeClock;
use App\Tests\Support\TestEnv;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Who may open which part of the back office: the role matrix is checked against every `/admin` route, the 30-minute idle lock, the IP
 * allowlist, re-confirmation of dangerous actions, and the trail that records what staff did.
 */
#[CoversClass(StaffAccess::class)]
#[CoversClass(RequireStaff::class)]
#[CoversClass(RequireStaffPermission::class)]
#[CoversClass(RequireAdminUnlock::class)]
#[CoversClass(AdminAuditTrail::class)]
#[CoversClass(StepUp::class)]
#[CoversClass(StaffController::class)]
#[CoversClass(AdminAuditController::class)]
#[CoversClass(AdminAuditReader::class)]
#[CoversClass(AdminInput::class)]
#[CoversClass(AdminNav::class)]
#[CoversClass(Csv::class)]
final class StaffAccessTest extends AdminTestCase
{
    /**
     * @return list<Route> the routes under `/admin` that sit behind the unlock (everything but the unlock page itself)
     */
    private function adminRoutes(): array
    {
        $routes = [];
        foreach ($this->app->container()->get(Router::class)->routes() as $route) {
            if (str_starts_with($route->pattern, '/admin') && !str_starts_with($route->pattern, '/admin/unlock')) {
                $routes[] = $route;
            }
        }

        return $routes;
    }

    private function permissionOf(Route $route): ?string
    {
        foreach ($route->middleware as $entry) {
            if (is_array($entry) && $entry[0] === RequireStaffPermission::class) {
                return (string) $entry[1]['permission'];
            }
        }

        return null;
    }

    /** A concrete path for a route pattern: ids become `1`, public ids 26 zeros, codes `pro`. */
    private function samplePath(Route $route): string
    {
        return (string) preg_replace_callback('/\{(\w+)(?::((?:[^{}]|\{[^{}]*\})+))?\}/', static function (array $m): string {
            $regex = $m[2] ?? '';
            if (str_contains($regex, '{26}')) {
                return str_repeat('0', 26);
            }
            if (str_starts_with($regex, '[0-9]')) {
                return '1';
            }

            return 'pro';
        }, $route->pattern);
    }

    public function testEveryAdminRouteNamesAPermissionOfTheMatrix(): void
    {
        $access = $this->app->container()->get(StaffAccess::class);
        $routes = $this->adminRoutes();
        self::assertGreaterThan(20, count($routes));
        foreach ($routes as $route) {
            $permission = $this->permissionOf($route);
            self::assertNotNull($permission, implode(',', $route->methods) . ' ' . $route->pattern . ' has no permission');
            self::assertContains($permission, $access->permissions(), $route->pattern);
        }
    }

    public function testRoleMatrixAgainstEveryRoute(): void
    {
        $access = $this->app->container()->get(StaffAccess::class);
        foreach ([StaffRole::Finance, StaffRole::Support, StaffRole::Content, StaffRole::Analyst, StaffRole::Superadmin] as $role) {
            $this->staff($role);
            foreach ($this->adminRoutes() as $route) {
                $permission = (string) $this->permissionOf($route);
                $path = $this->samplePath($route);
                $isRead = in_array('GET', $route->methods, true);
                if (!$isRead && $access->allows($role, $permission)) {
                    // An allowed change would really change something (switch networks off, edit prices); the refusals are what is tested here.
                    continue;
                }
                $response = $isRead ? $this->get($path) : $this->post($path, $this->confirm());
                $label = $role->value . ' ' . implode(',', $route->methods) . ' ' . $path . ' (' . $permission . ')';
                if ($access->allows($role, $permission)) {
                    self::assertNotSame(403, $response->status, $label . ' must be open');
                    self::assertLessThan(500, $response->status, $label . ' must not crash');
                } else {
                    self::assertSame(403, $response->status, $label . ' must be refused');
                }
            }
        }
    }

    public function testOrdinaryUsersAndGuestsSeeNothingOfAnyAdminRoute(): void
    {
        $this->useBrowser();
        [$user] = $this->ownerWithWorkspace('plain@example.com');
        $this->actAs($user);
        foreach ($this->adminRoutes() as $route) {
            $path = $this->samplePath($route);
            $response = in_array('GET', $route->methods, true) ? $this->get($path) : $this->post($path, []);
            self::assertSame(404, $response->status, $path);
        }
        $this->useBrowser();
        foreach ($this->adminRoutes() as $route) {
            if (in_array('GET', $route->methods, true)) {
                self::assertSame('/login', $this->get($this->samplePath($route))->header('Location'), $route->pattern);
            }
        }
    }

    public function testNavigationShowsOnlyWhatTheRoleMayOpen(): void
    {
        $this->staff(StaffRole::Support);
        $page = $this->text($this->get('/admin'));
        self::assertStringContainsString('Пользователи', $page);
        self::assertStringNotContainsString('Платежи', $page);
        self::assertStringNotContainsString('Журнал действий', $page);
        self::assertStringContainsString('Админка · Поддержка', $page);
    }

    public function testAdminLocksAgainAfterThirtyIdleMinutes(): void
    {
        $staff = $this->staff();
        $this->clock->advance(RequireAdminUnlock::IDLE - 60);
        self::assertNull($this->get('/admin')->header('Location'));
        // Activity moves the idle clock forward.
        $this->clock->advance(RequireAdminUnlock::IDLE - 60);
        self::assertNull($this->get('/admin')->header('Location'));
        $this->clock->advance(RequireAdminUnlock::IDLE + 60);
        self::assertSame('/admin/unlock', $this->get('/admin')->header('Location'));
        $this->unlockAdmin($staff);
        self::assertSame(200, $this->get('/admin')->status);
    }

    public function testIpAllowlistHidesTheAdminAreaFromOtherAddresses(): void
    {
        $this->app = TestEnv::app(['ADMIN_IP_ALLOWLIST' => '198.51.100.0/24, 192.0.2.7']);
        $this->app->container()->instance(\App\Support\Clock::class, $this->clock);
        $this->app->container()->instance(\App\Kernel\Mail\Mailer::class, $this->mailer);
        $staff = $this->staff(unlock: false);
        // 203.0.113.10 (the default test address) is outside the list: the area does not exist for it.
        self::assertSame(404, $this->get('/admin/unlock')->status);
        $this->remoteAddr = '198.51.100.20';
        self::assertSame(200, $this->get('/admin/unlock')->status);
        $this->unlockAdmin($staff);
        $this->remoteAddr = '203.0.113.99';
        self::assertSame(404, $this->get('/admin')->status);
        $this->remoteAddr = '192.0.2.7';
        self::assertSame(200, $this->get('/admin')->status);
    }

    public function testDangerousActionsNeedAFreshCodeAndAReason(): void
    {
        $this->staff();
        $victim = $this->createUser('victim@example.com');
        $block = '/admin/users/' . $victim->id . '/block';

        $this->post($block, ['reason' => 'Спам']);
        self::assertSame('active', $this->db->select('SELECT status FROM users WHERE id = ?', [$victim->id])[0]['status'], 'no code');

        $this->clock->advance(31);
        $this->post($block, ['reason' => 'Спам', 'confirm_code' => '000000']);
        self::assertSame('active', $this->db->select('SELECT status FROM users WHERE id = ?', [$victim->id])[0]['status'], 'wrong code');

        $this->post($block, $this->confirm());
        self::assertSame('active', $this->db->select('SELECT status FROM users WHERE id = ?', [$victim->id])[0]['status'], 'no reason');

        $this->post($block, $this->confirm(['reason' => 'Рассылает спам']));
        $row = $this->db->select('SELECT status, block_reason FROM users WHERE id = ?', [$victim->id])[0];
        self::assertSame(['blocked', 'Рассылает спам'], [$row['status'], $row['block_reason']]);

        // The same code cannot be used twice.
        $replayed = $this->confirm(['reason' => 'x']);
        $this->post('/admin/users/' . $victim->id . '/unblock', []);
        $this->post($block, $replayed);
        $this->post($block, $replayed);
        self::assertSame(1, (int) $this->db->select("SELECT COUNT(*) AS c FROM audit_log WHERE action = 'admin.user_blocked' AND meta_json LIKE '%\"reason\": \"x\"%'")[0]['c']);
    }

    public function testBlockedPersonSeesTheReasonAtSignIn(): void
    {
        $victim = $this->createUser('blocked@example.com');
        $this->app->container()->get(\App\Domain\User\UserRepository::class)->setStatus($victim->id, 'blocked', 'Нарушение правил рассылки.');
        $this->useBrowser();
        $this->signIn('blocked@example.com');
        $page = $this->text($this->get('/login'));
        self::assertStringContainsString('Причина: Нарушение правил рассылки.', $page);
        $this->app->container()->get(\App\Domain\User\UserRepository::class)->setStatus($victim->id, 'active');
        self::assertNull($this->db->select('SELECT block_reason FROM users WHERE id = ?', [$victim->id])[0]['block_reason']);
    }

    public function testEveryChangeIsWrittenToTheTrailAndDeniedAttemptsToo(): void
    {
        $this->staff(StaffRole::Support);
        $victim = $this->createUser('victim@example.com');
        $this->post('/admin/users/' . $victim->id . '/block', $this->confirm(['reason' => 'Тест']));
        $this->post('/admin/plans/pro', $this->confirm(['name' => 'Хакерский']));
        $actions = $this->adminActions();
        self::assertContains('admin.user_blocked', $actions);
        self::assertContains('admin.denied', $actions);
        self::assertGreaterThanOrEqual(1, count(array_keys($actions, 'admin.request', true)));
        $blocked = $this->db->select("SELECT meta_json, ip, actor_id FROM audit_log WHERE action = 'admin.user_blocked'")[0];
        self::assertEquals(['before' => 'active', 'after' => 'blocked', 'reason' => 'Тест'], json_decode((string) $blocked['meta_json'], true));
        self::assertSame($this->actor?->id, (int) $blocked['actor_id']);
        self::assertSame('203.0.113.10', $blocked['ip']);
    }

    public function testOwnerManagesStaffRoles(): void
    {
        $boss = $this->staff();
        $helper = $this->createUser('helper@example.com');
        self::assertSame(200, $this->get('/admin/staff')->status);

        $this->post('/admin/staff', ['email' => 'helper@example.com', 'role' => 'finance']);
        self::assertNull($this->app->container()->get(StaffAccess::class)->roleOf($helper), 'no code');

        $this->post('/admin/staff', $this->confirm(['email' => 'helper@example.com', 'role' => 'finance']));
        self::assertSame(StaffRole::Finance, $this->app->container()->get(StaffAccess::class)->roleOf($helper));
        self::assertStringContainsString('helper@example.com', $this->get('/admin/staff')->body);

        // The owner role cannot be given away, unknown people and unverified ones get nothing.
        $this->post('/admin/staff', $this->confirm(['email' => 'helper@example.com', 'role' => 'superadmin']));
        $this->createUser('unverified@example.com', false);
        $this->post('/admin/staff', $this->confirm(['email' => 'unverified@example.com', 'role' => 'support']));
        $this->post('/admin/staff', $this->confirm(['email' => 'nobody@example.com', 'role' => 'support']));
        self::assertCount(1, $this->app->container()->get(StaffAccess::class)->members());

        $this->post('/admin/staff/' . $helper->id . '/remove', $this->confirm());
        self::assertNull($this->app->container()->get(StaffAccess::class)->roleOf($helper));
        self::assertSame(['admin.staff_assigned', 'admin.staff_removed'], array_values(array_filter($this->adminActions(), static fn (string $a): bool => str_starts_with($a, 'admin.staff'))));
        self::assertNotNull($this->app->container()->get(StaffAccess::class)->roleOf($boss));
    }

    public function testStaffRoleOfABlockedPersonIsVoidAndUnknownPermissionsThrow(): void
    {
        $user = $this->createUser('helper@example.com');
        $access = $this->app->container()->get(StaffAccess::class);
        $access->assign($user->id, StaffRole::Support, null);
        self::assertTrue($access->can($user, 'users.view'));
        self::assertFalse($access->can($user, 'finance.view'));
        $blocked = new \App\Domain\User\User($user->id, $user->email, $user->emailVerifiedAt, null, $user->name, 'ru', 'UTC', null, null, false, 'blocked', $user->createdAt);
        self::assertNull($access->roleOf($blocked));
        $this->expectException(\InvalidArgumentException::class);
        $access->allows(StaffRole::Support, 'no.such.permission');
    }

    public function testAuditPageFiltersAndExportsWithoutFormulaInjection(): void
    {
        $this->staff();
        $victim = $this->createUser('victim@example.com', true, null, '=HYPERLINK("x")');
        $this->post('/admin/users/' . $victim->id . '/block', $this->confirm(['reason' => '=cmd|calc']));
        $page = $this->get('/admin/audit');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('Пользователь заблокирован', $page->body);

        $filtered = $this->get('/admin/audit?action=admin.user_blocked&subject=' . $victim->id);
        self::assertStringContainsString('Пользователь заблокирован', $filtered->body);
        self::assertSame(2, substr_count($filtered->body, '<tr>'), 'the header and the one matching row');
        self::assertStringContainsString('Записей нет', $this->get('/admin/audit?action=admin.user_blocked&subject=999999')->body);
        self::assertStringContainsString('Записей нет', $this->get('/admin/audit?from=2000-01-01&to=2000-01-02')->body);

        $csv = $this->get('/admin/audit/export?action=admin.user_blocked');
        self::assertSame(200, $csv->status);
        self::assertStringContainsString('text/csv', (string) $csv->header('Content-Type'));
        self::assertStringStartsWith("\xEF\xBB\xBF", $csv->body);
        self::assertStringContainsString('reason: =cmd|calc', $csv->body);
        self::assertContains('admin.audit_exported', $this->adminActions());
    }

    public function testInputHelpers(): void
    {
        $request = \App\Kernel\Http\Request::create('GET', '/x', query: ['page' => '3', 'from' => '2026-02-30', 'to' => '2026-03-01', 'a' => 'x', 'q' => '  hi  ']);
        self::assertSame(3, AdminInput::page($request));
        self::assertNull(AdminInput::date($request, 'from', 'Europe/Moscow'), 'impossible date');
        $to = AdminInput::date($request, 'to', 'Europe/Moscow', true);
        self::assertSame('2026-03-01 21:00:00', $to?->format('Y-m-d H:i:s'));
        self::assertSame('hi', AdminInput::text($request, 'q'));
        self::assertSame('', AdminInput::choice($request, 'a', ['b']));
        self::assertSame('?a=1&b=x', AdminInput::query(['a' => '1', 'b' => 'x', 'c' => '']));
        self::assertSame('', AdminInput::query([]));
    }

    public function testCsvEscaping(): void
    {
        $out = Csv::build(['a', 'b'], [[1, 'say "hi"'], ['=1+1', -5], [null, new \DateTimeImmutable('2026-01-02 03:04:05')]]);
        self::assertSame("\xEF\xBB\xBF\"a\";\"b\"\r\n\"1\";\"say \"\"hi\"\"\"\r\n\"'=1+1\";\"-5\"\r\n\"\";\"2026-01-02 03:04:05\"\r\n", $out);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = null;
        self::assertInstanceOf(FakeClock::class, $this->clock);
    }
}
