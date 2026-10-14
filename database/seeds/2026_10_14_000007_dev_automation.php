<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Seeder;
use Symfony\Component\Uid\Ulid;

/**
 * Demo automation for the demo workspace (idempotent): a queue with slots and two waiting posts, an evergreen pool, a recurring post, a
 * "when published, tell me" trigger, a cross-posting rule and an RSS feed (both switched off, so nothing reaches the internet). Only for
 * looking at the pages of stage 13 in every state.
 */
return new class () implements Seeder {
    public function run(Connection $db): void
    {
        $workspace = $db->select('SELECT id, owner_id, timezone FROM workspaces WHERE name = ? LIMIT 1', ['Кофейня «Зерно»']);
        if ($workspace === [] || $db->select('SELECT id FROM queues WHERE workspace_id = ? LIMIT 1', [$workspace[0]['id']]) !== []) {
            return;
        }
        $wsId = (int) $workspace[0]['id'];
        $ownerId = (int) $workspace[0]['owner_id'];
        $channels = $db->select('SELECT id, platform, title, alias FROM channels WHERE workspace_id = ? ORDER BY id', [$wsId]);
        $posts = $db->select('SELECT id, status FROM posts WHERE workspace_id = ? ORDER BY id', [$wsId]);
        if (count($channels) < 2 || count($posts) < 4) {
            return;
        }
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $scheduled = array_values(array_filter($posts, static fn (array $p): bool => $p['status'] === 'scheduled'));
        $drafts = array_values(array_filter($posts, static fn (array $p): bool => $p['status'] === 'draft'));
        $telegram = array_values(array_filter($channels, static fn (array $c): bool => $c['platform'] === 'telegram'));

        $queueId = (int) $db->table('queues')->insert(['public_id' => (string) new Ulid(), 'workspace_id' => $wsId, 'name' => 'Будни', 'paused' => 0, 'created_by' => $ownerId, 'created_at' => $now, 'updated_at' => $now]);
        foreach ([1, 2, 3, 4, 5] as $day) {
            foreach (['10:00', '15:00', '19:00'] as $time) {
                $db->table('queue_slots')->insert(['queue_id' => $queueId, 'weekday' => $day, 'at_time' => $time]);
            }
        }
        foreach ($channels as $channel) {
            $db->table('queue_channels')->insert(['queue_id' => $queueId, 'channel_id' => (int) $channel['id']]);
        }
        // Two fresh drafts wait in the queue; the scheduler places them into the next free slots within a minute.
        foreach (["**Утренний кофе** по акции: второй стакан в подарок.", "Мастер-класс по завариванию в воронке: запись в личных сообщениях."] as $i => $text) {
            $postId = (int) $db->table('posts')->insert([
                'public_id' => (string) new Ulid(), 'workspace_id' => $wsId, 'author_id' => $ownerId, 'status' => 'draft', 'base_text' => $text, 'media_ids_json' => '[]',
                'options_json' => json_encode(['buttons' => [], 'silent' => false, 'disable_preview' => false, 'pin' => false, 'delete_after_minutes' => null, 'first_comment' => ''], JSON_THROW_ON_ERROR),
                'per_network' => 0, 'timezone' => (string) $workspace[0]['timezone'], 'created_at' => $now, 'updated_at' => $now,
            ]);
            $channel = $channels[0];
            $db->table('post_variants')->insert(['post_id' => $postId, 'workspace_id' => $wsId, 'channel_id' => (int) $channel['id'], 'platform' => (string) $channel['platform'], 'channel_name' => (string) ($channel['alias'] ?? $channel['title'])]);
            $db->table('queue_entries')->insert(['queue_id' => $queueId, 'post_id' => $postId, 'position' => $i + 1, 'source' => 'manual', 'created_at' => $now]);
        }

        if ($drafts !== []) {
            $poolId = (int) $db->table('evergreen_pools')->insert(['public_id' => (string) new Ulid(), 'workspace_id' => $wsId, 'queue_id' => $queueId, 'name' => 'Полезное', 'min_interval_days' => 30, 'keep_ahead' => 2, 'enabled' => 0, 'created_at' => $now, 'updated_at' => $now]);
            $db->table('evergreen_posts')->insert(['pool_id' => $poolId, 'post_id' => (int) $drafts[0]['id'], 'created_at' => $now]);
            $db->table('recurring_rules')->insert([
                'public_id' => (string) new Ulid(), 'workspace_id' => $wsId, 'post_id' => (int) $drafts[0]['id'], 'frequency' => 'weekdays', 'interval_days' => 1, 'weekdays' => '1,3',
                'month_day' => null, 'at_time' => '10:00', 'timezone' => (string) $workspace[0]['timezone'], 'starts_on' => (new DateTimeImmutable('now'))->format('Y-m-d'),
                'ends_on' => null, 'max_count' => null, 'done_count' => 0, 'next_run_at' => null, 'enabled' => 0, 'created_by' => $ownerId, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        if ($scheduled !== []) {
            $db->table('triggers')->insert([
                'public_id' => (string) new Ulid(), 'workspace_id' => $wsId, 'post_id' => (int) $scheduled[0]['id'], 'event' => 'published', 'delay_minutes' => 120, 'action' => 'notify',
                'params_json' => json_encode(['message' => 'Посмотрите охват поста'], JSON_THROW_ON_ERROR), 'status' => 'pending', 'created_by' => $ownerId, 'created_at' => $now,
            ]);
        }
        $filters = json_encode(['include_tags' => ['новости'], 'exclude_tags' => ['реклама'], 'skip_forwarded' => true], JSON_THROW_ON_ERROR);
        $transforms = json_encode(['signatures' => ['@zerno_coffee'], 'links' => [], 'tail' => 'Больше на zerno.example'], JSON_THROW_ON_ERROR);
        if ($telegram !== []) {
            $ruleId = (int) $db->table('crosspost_rules')->insert([
                'public_id' => (string) new Ulid(), 'workspace_id' => $wsId, 'source_channel_id' => (int) $telegram[0]['id'], 'name' => 'Telegram → остальные каналы', 'enabled' => 0,
                'filters_json' => $filters, 'transforms_json' => $transforms, 'delay_minutes' => 0, 'mode' => 'draft', 'sync_edits' => 1, 'posts_created' => 3,
                'last_run_at' => $now, 'last_note' => 'Пост сохранён в черновики.', 'created_by' => $ownerId, 'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach ($channels as $channel) {
                if ((int) $channel['id'] !== (int) $telegram[0]['id']) {
                    $db->table('crosspost_targets')->insert(['rule_id' => $ruleId, 'channel_id' => (int) $channel['id']]);
                }
            }
        }
        $feedId = (int) $db->table('rss_feeds')->insert([
            'public_id' => (string) new Ulid(), 'workspace_id' => $wsId, 'name' => 'Блог кофейни', 'url' => 'https://zerno.example/feed.xml', 'template' => "{title}\n\n{summary}\n\n{link}",
            'mode' => 'queue', 'queue_id' => $queueId, 'interval_minutes' => 60, 'enabled' => 0, 'last_error' => 'Не удалось скачать ленту. Проверьте, что ссылка открывается.', 'error_count' => 2,
            'created_by' => $ownerId, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $db->table('rss_feed_channels')->insert(['feed_id' => $feedId, 'channel_id' => (int) $channels[0]['id']]);
    }
};
