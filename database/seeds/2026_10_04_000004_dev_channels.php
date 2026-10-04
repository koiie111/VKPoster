<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Seeder;

/**
 * Demo channels for the demo workspace (idempotent): a working Telegram channel, a group, a channel the bot was removed from,
 * and a paused one, so the channel list can be looked at in every state. Nothing here talks to Telegram.
 */
return new class () implements Seeder {
    public function run(Connection $db): void
    {
        $workspace = $db->select('SELECT id, owner_id FROM workspaces WHERE name = ? LIMIT 1', ['Кофейня «Зерно»']);
        if ($workspace === [] || $db->select('SELECT id FROM channels WHERE workspace_id = ? LIMIT 1', [$workspace[0]['id']]) !== []) {
            return;
        }
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $rights = json_encode(['rights' => ['post' => true, 'edit' => true, 'delete' => true, 'pin' => true]], JSON_THROW_ON_ERROR);
        $limited = json_encode(['rights' => ['post' => true, 'edit' => false, 'delete' => false, 'pin' => false]], JSON_THROW_ON_ERROR);
        $rows = [
            ['-1001000000001', 'Зерно — кофейня', null, 'zerno_coffee', 'channel', 'shared_bot', 'active', null, $rights],
            ['-1001000000002', 'Чат гостей «Зерна»', 'Гости', null, 'group', 'shared_bot', 'active', null, $limited],
            ['-1001000000003', 'Старый канал', null, 'zerno_old', 'channel', 'shared_bot', 'revoked', 'Бот больше не может писать в этот канал: его удалили или забрали права. Добавьте бота администратором снова.', $rights],
            ['-1001000000004', 'Закрытый клуб друзей', null, null, 'channel', 'own_bot', 'paused', null, $rights],
        ];
        foreach ($rows as [$externalId, $title, $alias, $username, $kind, $mode, $status, $error, $settings]) {
            $db->table('channels')->insert([
                'public_id' => (string) new Symfony\Component\Uid\Ulid(),
                'workspace_id' => (int) $workspace[0]['id'],
                'platform' => 'telegram',
                'external_id' => $externalId,
                'mode' => $mode,
                'title' => $title,
                'alias' => $alias,
                'username' => $username,
                'kind' => $kind,
                'status' => $status,
                'settings_json' => $settings,
                'last_health_at' => $now,
                'last_error' => $error,
                'created_by' => (int) $workspace[0]['owner_id'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};
