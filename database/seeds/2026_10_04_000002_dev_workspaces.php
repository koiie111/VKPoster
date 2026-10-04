<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Seeder;

/**
 * Local demo workspace for screenshots and manual checks (idempotent; refused in production by `seed`):
 * "Кофейня «Зерно»" owned by demo@ezposter.local with an admin, an editor and a client, one open invitation
 * and a few journal entries. Other demo accounts get their personal workspace on first visit.
 */
return new class () implements Seeder {
    public function run(Connection $db): void
    {
        $owner = $db->select('SELECT id FROM users WHERE email = ?', ['demo@ezposter.local']);
        if ($owner === [] || $db->select('SELECT id FROM workspaces WHERE owner_id = ? AND is_personal = 0', [$owner[0]['id']]) !== []) {
            return;
        }
        $ownerId = (int) $owner[0]['id'];
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $workspaceId = (int) $db->table('workspaces')->insert([
            'public_id' => (string) new Symfony\Component\Uid\Ulid(),
            'owner_id' => $ownerId,
            'name' => 'Кофейня «Зерно»',
            'timezone' => 'Europe/Moscow',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $db->table('workspace_members')->insert(['workspace_id' => $workspaceId, 'user_id' => $ownerId, 'public_id' => (string) new Symfony\Component\Uid\Ulid(), 'role' => 'owner', 'joined_at' => $now]);
        foreach ([['admin', 'Мария Администратор', 'admin'], ['editor', 'Пётр Редактор', 'editor'], ['client', 'Клиент Кофейни', 'client']] as [$slug, $name, $role]) {
            $email = $slug . '@ezposter.local';
            $existing = $db->select('SELECT id FROM users WHERE email = ?', [$email]);
            $userId = $existing !== [] ? (int) $existing[0]['id'] : (int) $db->table('users')->insert([
                'email' => $email,
                'email_verified_at' => $now,
                'name' => $name,
                'consent_version' => 'seed',
                'consent_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $db->table('workspace_members')->insert([
                'workspace_id' => $workspaceId,
                'user_id' => $userId,
                'public_id' => (string) new Symfony\Component\Uid\Ulid(),
                'role' => $role,
                'channels_restricted' => $role === 'client' ? 1 : 0,
                'invited_by' => $ownerId,
                'joined_at' => $now,
            ]);
        }
        $db->table('invitations')->insert([
            'public_id' => (string) new Symfony\Component\Uid\Ulid(),
            'workspace_id' => $workspaceId,
            'email' => 'barista@example.com',
            'role' => 'author',
            'token_hash' => hash('sha256', 'seed-' . $workspaceId),
            'invited_by' => $ownerId,
            'expires_at' => (new DateTimeImmutable('+7 days', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
            'created_at' => $now,
        ]);
        foreach ([
            ['workspace.created', 'workspace', ['personal' => false]],
            ['member.invited', 'invitation', ['email' => 'barista@example.com', 'role' => 'author']],
            ['member.role_changed', 'member', ['email' => 'editor@ezposter.local', 'from' => 'viewer', 'to' => 'editor']],
        ] as [$action, $subject, $meta]) {
            $db->table('audit_log')->insert([
                'workspace_id' => $workspaceId,
                'actor_id' => $ownerId,
                'action' => $action,
                'subject_type' => $subject,
                'subject_id' => 'seed',
                'ip' => '127.0.0.1',
                'meta_json' => json_encode($meta, JSON_THROW_ON_ERROR),
                'created_at' => $now,
            ]);
        }
    }
};
