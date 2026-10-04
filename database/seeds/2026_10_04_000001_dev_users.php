<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Seeder;

/**
 * Local demo account: demo@ezposter.local / demo-password-2026 (confirmed email, no 2FA).
 * Refused in production by `seed`. Idempotent: an existing account is left untouched.
 */
return new class () implements Seeder {
    public function run(Connection $db): void
    {
        if ($db->select('SELECT id FROM users WHERE email = ?', ['demo@ezposter.local']) !== []) {
            return;
        }
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
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
};
