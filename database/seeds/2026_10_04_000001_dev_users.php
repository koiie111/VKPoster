<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Seeder;

/**
 * Local demo accounts (refused in production by `seed`; idempotent, existing accounts are left untouched):
 * - demo@ezposter.local / demo-password-2026: confirmed email, no 2FA;
 * - social@ezposter.local: no password, signs in through (fake) VK ID and Google only.
 */
return new class () implements Seeder {
    public function run(Connection $db): void
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $this->demo($db, $now);
        $this->social($db, $now);
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
            'consent_version' => 'seed',
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
            'consent_version' => 'seed',
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
