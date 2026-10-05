<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\Admin\StaffAccess;
use App\Domain\Admin\StaffRole;
use App\Domain\User\User;
use App\Domain\User\UserRepository;

/**
 * Base class for back office tests: staff members of any role who are signed in, have two-factor protection and have unlocked the
 * admin area, and a helper that adds a fresh authenticator code to the form of a dangerous action.
 */
abstract class AdminTestCase extends BillingTestCase
{
    /** The staff member whose browser is active (the one `confirm()` makes codes for). */
    protected ?User $actor = null;

    protected static int $staffCounter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        // Rows without a foreign key to the people survive the clean-up of users: start every test without them.
        foreach (['data_requests', 'invite_codes', 'cms_pages', 'cms_blocks', 'announcements', 'mail_templates', 'email_campaigns', 'support_tickets'] as $table) {
            $this->db->execute('DELETE FROM ' . $table);
        }
        $this->restorePriceList();
    }

    protected function tearDown(): void
    {
        // What a test changed for everybody (settings, the price list) must not leak into the next one.
        $this->db->execute('DELETE FROM app_settings');
        $this->restorePriceList();
        parent::tearDown();
    }

    /**
     * The tests here edit the shared price list: put every plan back as `config/billing.php` defines it.
     */
    protected function restorePriceList(): void
    {
        $catalog = (require dirname(__DIR__, 2) . '/config/billing.php')(new \App\Kernel\Env([]))['catalog'];
        foreach ($catalog as $code => $definition) {
            $this->db->execute('UPDATE plans SET name = ?, limits_json = ?, features_json = ?, is_public = 1 WHERE code = ?', [$definition['name'], json_encode($definition['limits']), json_encode(array_values($definition['features'])), $code]);
            $planId = (int) $this->db->select('SELECT id FROM plans WHERE code = ?', [$code])[0]['id'];
            $this->db->execute('DELETE FROM plan_prices WHERE plan_id = ?', [$planId]);
            foreach ($definition['prices'] as $period => $amount) {
                $this->db->table('plan_prices')->insert(['plan_id' => $planId, 'period' => $period, 'currency' => 'RUB', 'amount' => $amount]);
            }
        }
    }

    /**
     * Create a staff member, sign in as them, switch two-factor on and (by default) unlock the admin area.
     */
    protected function staff(StaffRole $role = StaffRole::Superadmin, bool $unlock = true, bool $twoFactor = true): User
    {
        $email = 'staff' . (++self::$staffCounter) . '-' . $role->value . '@example.com';
        $user = $this->createUser($email, true, null, 'Сотрудник ' . $role->label());
        if ($role === StaffRole::Superadmin) {
            $this->app->container()->get(UserRepository::class)->setSuperadmin($user->id, true);
        } else {
            $this->app->container()->get(StaffAccess::class)->assign($user->id, $role, null);
        }
        $this->actAs($user);
        if ($twoFactor) {
            $this->enableTwoFactor($user);
        }
        if ($unlock) {
            $this->unlockAdmin($user);
        }
        $fresh = $this->app->container()->get(UserRepository::class)->find($user->id);
        self::assertNotNull($fresh);
        $this->actor = $fresh;

        return $fresh;
    }

    protected function unlockAdmin(User $user): void
    {
        $response = $this->post('/admin/unlock', ['code' => $this->totpCode($user->id)]);
        self::assertSame('/admin', $response->header('Location'));
    }

    /**
     * The fields of a dangerous action plus a fresh authenticator code of the current staff member (the time step of the previous code is
     * spent, so the clock moves on first).
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    protected function confirm(array $fields = []): array
    {
        self::assertNotNull($this->actor, 'sign in a staff member first');
        $this->clock->advance(31);

        return $fields + ['confirm_code' => $this->totpCode($this->actor->id)];
    }

    /**
     * @return list<string> audit actions in order, optionally only those starting with a prefix
     */
    protected function adminActions(string $prefix = 'admin.'): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['action'],
            $this->db->select('SELECT action FROM audit_log WHERE action LIKE ? ORDER BY id', [$prefix . '%']),
        );
    }
}
