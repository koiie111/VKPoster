<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Seeder;

/**
 * Local demo accounts (refused in production by `seed`; idempotent, existing accounts are left untouched):
 * - demo@ezposter.local / demo-password-2026: confirmed email, no 2FA;
 * - social@ezposter.local: no password, signs in through (fake) VK ID and Google only;
 * - staff@ezposter.local / staff-password-2026: staff (superadmin). `/dev/login-as/staff@ezposter.local` also opens the admin area without a
 *   two-factor code (local development only); a real staff account is made with `user:create-admin`.
 * - finance@, support@, content@, analyst@ezposter.local (same password pattern `<name>-password-2026`): one staff account of each role
 *   with the permissions of that role, to look at what the back office shows each of them (`/dev/login-as/finance@ezposter.local`).
 */
return new class () implements Seeder {
    public function run(Connection $db): void
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $this->demo($db, $now);
        $this->social($db, $now);
        $this->admin($db, $now);
        foreach (['finance' => 'Финансы', 'support' => 'Поддержка', 'content' => 'Контент', 'analyst' => 'Аналитик'] as $role => $title) {
            $this->role($db, $now, $role, $title);
        }
    }

    private function role(Connection $db, string $now, string $role, string $title): void
    {
        $email = $role . '@ezposter.local';
        if ($db->select('SELECT id FROM users WHERE email = ?', [$email]) !== []) {
            return;
        }
        $id = $db->table('users')->insert([
            'email' => $email,
            'email_verified_at' => $now,
            'password_hash' => password_hash($role . '-password-2026', PASSWORD_ARGON2ID),
            'password_changed_at' => $now,
            'name' => $title . ' Демо',
            'consent_version' => (new \App\Domain\Legal\LegalDocuments(dirname(__DIR__, 2) . '/resources/legal'))->consentVersion(),
            'consent_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $db->table('staff_members')->insert(['user_id' => $id, 'role' => $role, 'created_at' => $now]);
    }

    private function admin(Connection $db, string $now): void
    {
        if ($db->select('SELECT id FROM users WHERE email = ?', ['staff@ezposter.local']) !== []) {
            return;
        }
        $db->table('users')->insert([
            'email' => 'staff@ezposter.local',
            'email_verified_at' => $now,
            'password_hash' => password_hash('staff-password-2026', PASSWORD_ARGON2ID),
            'password_changed_at' => $now,
            'name' => 'Сотрудник Демо',
            'is_superadmin' => 1,
            'consent_version' => (new \App\Domain\Legal\LegalDocuments(dirname(__DIR__, 2) . '/resources/legal'))->consentVersion(),
            'consent_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function demo(Connection $db, string $now): void
    {
        if ($db->select('SELECT id FROM users WHERE email = ?', ['demo@ezposter.local']) !== []) {
            return;
        }
        $db->table('users')->insert([
            'email' => 'demo@ezposter.local',
            'email_verified_at' => $now,
            'password_hash' => password_hash('demo-password-2026', PASSWORD_ARGON2ID),
            'password_changed_at' => $now,
            'name' => 'Анна Демо',
            'consent_version' => (new \App\Domain\Legal\LegalDocuments(dirname(__DIR__, 2) . '/resources/legal'))->consentVersion(),
            'consent_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function social(Connection $db, string $now): void
    {
        if ($db->select('SELECT id FROM users WHERE email = ?', ['social@ezposter.local']) !== []) {
            return;
        }
        $id = $db->table('users')->insert([
            'email' => 'social@ezposter.local',
            'email_verified_at' => $now,
            'name' => 'Иван Соцсети',
            'consent_version' => (new \App\Domain\Legal\LegalDocuments(dirname(__DIR__, 2) . '/resources/legal'))->consentVersion(),
            'consent_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach ([['vkid', '1000001', 'Иван Соцсети'], ['google', 'seed-google-1', 'Ivan S.']] as [$provider, $providerId, $name]) {
            $db->table('user_identities')->insert([
                'user_id' => $id,
                'provider' => $provider,
                'provider_user_id' => $providerId,
                'display_name' => $name,
                'linked_at' => $now,
            ]);
        }
    }
};
