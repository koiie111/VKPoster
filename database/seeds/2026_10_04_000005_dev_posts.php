<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Seeder;
use Symfony\Component\Uid\Ulid;

/**
 * Demo posts for the demo workspace (idempotent): planned posts in the coming days, a draft, a published post, one that failed and one
 * whose outcome is unknown, so the calendar and the post page can be looked at in every state. Nothing here is sent anywhere: the
 * planned ones are in the future, the others are already finished.
 */
return new class () implements Seeder {
    public function run(Connection $db): void
    {
        $workspace = $db->select('SELECT id, owner_id, timezone FROM workspaces WHERE name = ? LIMIT 1', ['Кофейня «Зерно»']);
        if ($workspace === [] || $db->select('SELECT id FROM posts WHERE workspace_id = ? LIMIT 1', [$workspace[0]['id']]) !== []) {
            return;
        }
        $channels = $db->select('SELECT id, title, platform, alias FROM channels WHERE workspace_id = ? ORDER BY id', [$workspace[0]['id']]);
        if (count($channels) < 2) {
            return;
        }
        $wsId = (int) $workspace[0]['id'];
        $ownerId = (int) $workspace[0]['owner_id'];
        $zone = new DateTimeZone((string) $workspace[0]['timezone']);
        $utc = new DateTimeZone('UTC');
        $format = 'Y-m-d H:i:s.u';
        $now = new DateTimeImmutable('now', $utc);
        $at = static fn (string $modify, string $time) => (new DateTimeImmutable('now', $zone))->modify($modify)->setTime((int) substr($time, 0, 2), (int) substr($time, 3, 2))->setTimezone($utc);
        $name = static fn (array $channel): string => (string) ($channel['alias'] ?? $channel['title']);

        $make = function (string $text, string $status, ?DateTimeImmutable $when, array $channelIdx, string $pubStatus, ?string $error = null) use ($db, $wsId, $ownerId, $channels, $now, $format, $name): void {
            $postId = (int) $db->table('posts')->insert([
                'public_id' => (string) new Ulid(), 'workspace_id' => $wsId, 'author_id' => $ownerId, 'status' => $status, 'base_text' => $text,
                'media_ids_json' => '[]', 'options_json' => json_encode(['buttons' => [], 'silent' => false, 'disable_preview' => false, 'pin' => false, 'delete_after_minutes' => null, 'first_comment' => ''], JSON_THROW_ON_ERROR),
                'per_network' => 0, 'scheduled_at' => $when?->format($format), 'timezone' => 'Europe/Moscow',
                'published_at' => $status === 'published' ? $when?->format($format) : null, 'created_at' => $now->format($format), 'updated_at' => $now->format($format),
            ]);
            foreach ($channelIdx as $i) {
                $channel = $channels[$i];
                $variantId = (int) $db->table('post_variants')->insert([
                    'post_id' => $postId, 'workspace_id' => $wsId, 'channel_id' => (int) $channel['id'], 'platform' => (string) $channel['platform'], 'channel_name' => $name($channel),
                ]);
                if ($pubStatus === '' || $when === null) {
                    continue;
                }
                $publicId = (string) new Ulid();
                $db->table('publications')->insert([
                    'public_id' => $publicId, 'workspace_id' => $wsId, 'post_id' => $postId, 'variant_id' => $variantId, 'channel_id' => (int) $channel['id'],
                    'status' => $pubStatus, 'attempt' => $pubStatus === 'queued' ? 0 : 1, 'due_at' => $when->format($format), 'run_at' => $when->format($format),
                    'idempotency_key' => hash('sha256', $publicId), 'external_post_id' => $pubStatus === 'sent' ? '101' : null,
                    'external_ids_json' => $pubStatus === 'sent' ? '["101"]' : null, 'external_url' => $pubStatus === 'sent' ? 'https://t.me/zerno_coffee/101' : null,
                    'error_message' => $error, 'sent_at' => $pubStatus === 'sent' ? $when->format($format) : null,
                    'created_at' => $now->format($format), 'updated_at' => $now->format($format),
                ]);
            }
        };

        $make("**Новое меню уже в кофейне!**\nЗаходите попробовать тыквенный латте и свежие круассаны.", 'scheduled', $at('+1 day', '12:30'), [0, 1], 'queued');
        $make("Сегодня до 18:00 — _капучино в подарок_ к любому десерту. [Меню](https://example.com/menu)", 'scheduled', $at('+3 days', '10:00'), [0], 'queued');
        $make("Расскажем про новых бариста: знакомьтесь с командой.", 'scheduled', $at('+5 days', '15:00'), [1], 'queued');
        $make("Идея для поста про зимние напитки (пока без даты).", 'draft', null, [0], '');
        $make("Доброе утро! Открылись в 8:00, ждём вас.", 'published', $at('-1 day', '09:00'), [0], 'sent');
        $make("Розыгрыш подарочных сертификатов — условия в комментариях.", 'failed', $at('-2 days', '14:00'), [1], 'failed', 'Бот не может писать в этот чат: у него нет права «Публикация сообщений».');
        $make("Пост, который могли опубликовать дважды: проверьте канал.", 'failed', $at('-1 day', '18:00'), [0], 'unknown', 'Соединение оборвалось после отправки: неизвестно, вышел ли пост.');
    }
};
