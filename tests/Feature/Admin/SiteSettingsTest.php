<?php

declare(strict_types=1);

namespace App\Tests\Feature\Admin;

use App\Domain\Admin\StaffRole;
use App\Domain\Auth\RegistrationGate;
use App\Domain\Auth\Social\SocialAuthService;
use App\Integrations\OAuth\SocialProfile;
use App\Domain\Auth\Social\SocialStatus;
use App\Domain\Settings\Settings;
use App\Domain\Settings\SiteSettings;
use App\Domain\Workspace\WorkspaceService;
use App\Http\Controllers\Admin\SiteSettingsController;
use App\Http\Middleware\Maintenance;
use App\Tests\Support\AdminTestCase;
use App\Tests\Support\TestEnv;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Switches the owner changes without a deploy: maintenance mode (everybody but staff is told to wait; machines keep working), who may
 * register (open, by invitation code, closed), the lower limit of workspaces, contacts, and the shared cache of the settings.
 */
#[CoversClass(SiteSettings::class)]
#[CoversClass(SiteSettingsController::class)]
#[CoversClass(Maintenance::class)]
#[CoversClass(RegistrationGate::class)]
#[CoversClass(Settings::class)]
final class SiteSettingsTest extends AdminTestCase
{
    private function site(): SiteSettings
    {
        return $this->app->container()->get(SiteSettings::class);
    }

    public function testOnlyTheOwnerOpensTheSettings(): void
    {
        $this->staff(StaffRole::Support);
        self::assertSame(403, $this->get('/admin/settings')->status);
        self::assertSame(403, $this->post('/admin/settings', [])->status);
        $this->staff();
        self::assertSame(200, $this->get('/admin/settings')->status);
    }

    public function testRiskySwitchesNeedACodeAndAreAudited(): void
    {
        $this->staff();
        $form = ['maintenance' => '1', 'maintenance_message' => 'Обновляем базу', 'registration' => 'open', 'support_email' => 'help@example.com', 'support_telegram' => 'helpbot', 'requisites' => 'ИП Иванов', 'max_workspaces' => '3'];
        $this->post('/admin/settings', $form);
        self::assertFalse($this->site()->maintenanceOn(), 'no code, nothing changed');

        $this->post('/admin/settings', $this->confirm($form));
        self::assertTrue($this->site()->maintenanceOn());
        self::assertSame('Обновляем базу', $this->site()->maintenanceMessage());
        self::assertSame('help@example.com', $this->site()->supportEmail());
        self::assertSame('@helpbot', $this->site()->supportTelegram());
        self::assertSame(3, $this->site()->maxWorkspacesPerUser());
        $audit = json_decode((string) $this->db->select("SELECT meta_json FROM audit_log WHERE action = 'admin.settings_changed' ORDER BY id DESC LIMIT 1")[0]['meta_json'], true);
        self::assertStringContainsString('"maintenance":false', $audit['before']);
        self::assertStringContainsString('"maintenance":true', $audit['after']);

        // Contacts alone need no code.
        $this->post('/admin/settings', ['maintenance' => '1', 'registration' => 'open', 'support_email' => 'other@example.com', 'max_workspaces' => '3']);
        self::assertSame('other@example.com', $this->site()->supportEmail());
    }

    public function testValidationRefusesNonsense(): void
    {
        $this->staff();
        $this->post('/admin/settings', ['registration' => 'everybody', 'support_email' => 'not-an-email', 'support_telegram' => '@@@', 'max_workspaces' => '-1']);
        self::assertSame('open', $this->site()->registrationMode());
        self::assertSame(0, $this->site()->maxWorkspacesPerUser());
        self::assertSame('', $this->site()->supportTelegram());
        self::assertSame($this->app->container()->get(\App\Kernel\Config::class)->string('mail.support'), $this->site()->supportEmail(), 'the environment address is the fallback');
    }

    public function testMaintenanceModeLetsOnlyStaffAndMachinesIn(): void
    {
        [$user] = $this->ownerWithWorkspace('plain@example.com');
        $this->app->container()->get(Settings::class)->set('site.maintenance', true, null);
        $this->app->container()->get(Settings::class)->set('site.maintenance_message', 'Мы вернёмся к 12:00', null);

        $this->useBrowser();
        $page = $this->get('/');
        self::assertSame(503, $page->status);
        self::assertStringContainsString('Мы вернёмся к 12:00', $page->body);
        self::assertSame('600', $page->header('Retry-After'));
        self::assertSame(503, $this->get('/register')->status);
        // What must keep working.
        self::assertSame(200, $this->get('/healthz')->status);
        self::assertSame(200, $this->get('/login')->status);
        self::assertNotSame(503, $this->get('/legal/offer')->status);
        self::assertNotSame(503, $this->get('/status')->status);
        self::assertNotSame(503, $this->request('POST', '/webhooks/billing/fake', [], ['Content-Type' => 'application/json'])->status, 'payment notifications are not blocked');
        $json = $this->get('/api/x', ['Accept' => 'application/json']);
        self::assertSame(503, $json->status);
        self::assertStringContainsString('maintenance', $json->body);

        // A signed-in ordinary person is told to wait too; staff work as usual.
        $this->actAs($user);
        self::assertSame(503, $this->get('/app')->status);
        $this->staff();
        self::assertSame(200, $this->get('/admin')->status);
        self::assertSame(200, $this->get('/admin/settings')->status);
        $this->app->container()->get(Settings::class)->set('site.maintenance', false, null);
        $this->actAs($user);
        self::assertNotSame(503, $this->get('/app')->status);
    }

    public function testRegistrationClosedAndByInvitation(): void
    {
        $settings = $this->app->container()->get(Settings::class);
        $this->useBrowser();
        $input = fn (string $email, string $invite = ''): array => ['name' => 'Новый', 'email' => $email, 'password' => 'a long enough passphrase 2026', 'consent' => '1', 'invite' => $invite];

        $settings->set('site.registration', 'closed', null);
        self::assertStringContainsString('Регистрация сейчас закрыта', $this->text($this->get('/register')));
        $this->post('/register', $input('closed@example.com'));
        self::assertSame([], $this->db->select('SELECT 1 FROM users WHERE email = ?', ['closed@example.com']));

        $settings->set('site.registration', 'invite', null);
        $page = $this->get('/register');
        self::assertStringContainsString('Код приглашения', $page->body);
        $this->post('/register', $input('nocode@example.com'));
        $this->post('/register', $input('wrong@example.com', 'NOSUCHCODE'));
        self::assertSame([], $this->db->select("SELECT 1 FROM users WHERE email IN ('nocode@example.com', 'wrong@example.com')"));

        $gate = $this->app->container()->get(RegistrationGate::class);
        $code = $gate->create('для блога', 1, null, null);
        self::assertMatchesRegularExpression('/^[0-9A-F]{10}$/', $code);
        self::assertSame([], $this->db->select('SELECT 1 FROM invite_codes WHERE code_hash = ?', [$code]), 'only the hash is stored');
        $this->post('/register', $input('invited@example.com', strtolower(substr($code, 0, 5)) . '-' . substr($code, 5)));
        self::assertNotSame([], $this->db->select('SELECT 1 FROM users WHERE email = ?', ['invited@example.com']), 'a code is accepted however it is typed');
        $this->post('/register', $input('second@example.com', $code));
        self::assertSame([], $this->db->select('SELECT 1 FROM users WHERE email = ?', ['second@example.com']), 'a one-use code is used up');

        // An address that already has an account does not burn a code.
        $fresh = $gate->create('', 1, null, null);
        $this->post('/register', $input('invited@example.com', $fresh));
        self::assertTrue($gate->valid($fresh));
        $gate->revoke((int) $this->db->select('SELECT id FROM invite_codes ORDER BY id DESC LIMIT 1')[0]['id']);
        self::assertFalse($gate->valid($fresh), 'a revoked code does not work');
        $this->clock->advance(1);
        $expiring = $gate->create('', 5, $this->clock->now()->modify('+1 day'), null);
        self::assertTrue($gate->valid($expiring));
        $this->clock->advance(2 * 86400);
        self::assertFalse($gate->valid($expiring), 'an expired code does not work');
    }

    public function testAdminCreatesAndRevokesCodes(): void
    {
        $this->staff();
        $this->post('/admin/settings/invites', ['note' => 'партнёр', 'max_uses' => '3', 'days' => '7']);
        $page = $this->get('/admin/settings');
        self::assertMatchesRegularExpression('/Новый код: [0-9A-F]{10}/', $page->body);
        self::assertStringNotContainsString('Новый код', $this->get('/admin/settings')->body, 'the plain code is shown once');
        $row = $this->db->select('SELECT id, max_uses, note FROM invite_codes')[0];
        self::assertSame([3, 'партнёр'], [(int) $row['max_uses'], $row['note']]);
        $this->post('/admin/settings/invites/' . $row['id'] . '/revoke', []);
        self::assertNotNull($this->db->select('SELECT revoked_at FROM invite_codes WHERE id = ?', [$row['id']])[0]['revoked_at']);
    }

    public function testSocialSignUpFollowsTheRegistrationMode(): void
    {
        $service = $this->app->container()->get(SocialAuthService::class);
        $profile = new SocialProfile('vkid', 'vk-77', null, false, 'Гость');
        $settings = $this->app->container()->get(Settings::class);
        $open = $service->resolve($profile, null);
        $settings->set('site.registration', 'invite', null);
        $invite = $service->resolve($profile, null);
        $settings->set('site.registration', 'closed', null);
        $closed = $service->resolve($profile, null);
        self::assertSame([SocialStatus::NewAccount, SocialStatus::RegistrationClosed, SocialStatus::RegistrationClosed], [$open->status, $invite->status, $closed->status]);
    }

    public function testWorkspaceLimitSetByTheOwnerAppliesBelowTheBuiltInOne(): void
    {
        [$user] = $this->ownerWithWorkspace('limited@example.com');
        $service = $this->app->container()->get(WorkspaceService::class);
        $this->app->container()->get(Settings::class)->set('limits.max_workspaces_per_user', 1, null);
        self::assertNull($service->create($user, 'Второе'), 'one workspace is the owner limit');
        $this->app->container()->get(Settings::class)->set('limits.max_workspaces_per_user', 0, null);
        self::assertNotNull($service->create($user, 'Второе'));
    }

    public function testSettingsAreSharedThroughRedisAndDroppedOnChange(): void
    {
        $redis = TestEnv::redis();
        $a = new Settings($this->db, $this->clock, $redis);
        $b = new Settings($this->db, $this->clock, $redis);
        $a->set('demo.value', 'one', null);
        self::assertSame('one', $b->get('demo.value'));
        self::assertNotFalse($redis->get('app:settings'), 'the copy is in Redis');
        $a->set('demo.value', 'two', null);
        self::assertFalse($redis->get('app:settings'), 'a change drops the shared copy');
        $c = new Settings($this->db, $this->clock, $redis);
        self::assertSame('two', $c->get('demo.value'));
        $a->forget('demo.value');
        self::assertNull((new Settings($this->db, $this->clock, $redis))->get('demo.value'));
        // Without Redis the table is the truth.
        self::assertNull((new Settings($this->db, $this->clock))->get('demo.value'));
    }
}
