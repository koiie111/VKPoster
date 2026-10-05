<?php

declare(strict_types=1);

namespace App\Domain\Content;

use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;
use DateTimeImmutable;

/**
 * Notices the owner shows inside the app: to everybody, or only to workspaces on certain plans or with channels in certain networks, for a
 * period. A person can close a notice (it stays closed for them); a `critical` one cannot be closed. Text is plain and escaped when shown.
 */
final class Announcements
{
    public const LEVELS = ['info' => 'Сообщение', 'warning' => 'Предупреждение', 'critical' => 'Важное (нельзя закрыть)'];

    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    /**
     * @param list<string> $plans plan codes, empty = every plan
     * @param list<string> $platforms networks, empty = every workspace
     * @return list<string> problems in the editor's words; empty when saved
     */
    public function save(?int $id, string $title, string $body, string $level, array $plans, array $platforms, ?DateTimeImmutable $startsAt, ?DateTimeImmutable $endsAt, ?int $actorId): array
    {
        $errors = [];
        $title = trim($title);
        $body = trim($body);
        if (mb_strlen($title) < 3 || mb_strlen($title) > 150) {
            $errors[] = 'Заголовок — от 3 до 150 символов.';
        }
        if (mb_strlen($body) > 1000) {
            $errors[] = 'Текст — до 1000 символов.';
        }
        if (!isset(self::LEVELS[$level])) {
            $errors[] = 'Выберите вид объявления.';
        }
        if ($startsAt !== null && $endsAt !== null && $endsAt <= $startsAt) {
            $errors[] = 'Конец показа должен быть позже начала.';
        }
        if ($errors !== []) {
            return $errors;
        }
        $clean = static fn (array $list): ?string => ($list = array_values(array_filter($list, static fn (string $v): bool => preg_match('/^[a-z0-9_]{1,32}$/', $v) === 1))) === [] ? null : json_encode($list, JSON_THROW_ON_ERROR);
        $values = [
            'title' => $title,
            'body' => $body,
            'level' => $level,
            'plans_json' => $clean($plans),
            'platforms_json' => $clean($platforms),
            'starts_at' => DbTime::format($startsAt ?? $this->clock->now()),
            'ends_at' => $endsAt === null ? null : DbTime::format($endsAt),
        ];
        if ($id === null) {
            $this->db->table('announcements')->insert($values + ['is_active' => 1, 'created_by' => $actorId, 'created_at' => DbTime::format($this->clock->now())]);
        } else {
            $this->db->table('announcements')->where('id', '=', $id)->update($values);
        }

        return [];
    }

    public function setActive(int $id, bool $active): void
    {
        $this->db->execute('UPDATE announcements SET is_active = ? WHERE id = ?', [$active ? 1 : 0, $id]);
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM announcements WHERE id = ?', [$id]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $rows = $this->db->select('SELECT * FROM announcements WHERE id = ?', [$id]);

        return $rows === [] ? null : $this->row($rows[0]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return array_map($this->row(...), $this->db->select('SELECT * FROM announcements ORDER BY id DESC LIMIT 100'));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function row(array $row): array
    {
        foreach (['plans_json' => 'plans', 'platforms_json' => 'platforms'] as $column => $key) {
            $decoded = is_string($row[$column]) ? json_decode($row[$column], true) : [];
            $row[$key] = is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
        }
        foreach (['starts_at', 'ends_at', 'created_at'] as $key) {
            $row[$key] = DbTime::parse($row[$key]);
        }
        $now = $this->clock->now();
        $row['live'] = (int) $row['is_active'] === 1 && $row['starts_at'] <= $now && ($row['ends_at'] === null || $row['ends_at'] > $now);

        return $row;
    }

    /**
     * Notices that are on now, for this person in this workspace: the audience matches and the person has not closed them.
     *
     * @param string|null $planCode plan of the workspace on screen (null outside a workspace: only notices for everybody)
     * @param list<string> $platforms networks of the workspace's channels
     * @return list<array{id: int, title: string, body: string, level: string}>
     */
    public function visible(int $userId, ?string $planCode, array $platforms): array
    {
        $rows = $this->db->select(
            'SELECT a.* FROM announcements a WHERE a.is_active = 1 AND a.starts_at <= ? AND (a.ends_at IS NULL OR a.ends_at > ?) '
            . 'AND NOT EXISTS (SELECT 1 FROM announcement_dismissals d WHERE d.announcement_id = a.id AND d.user_id = ?) ORDER BY a.id DESC',
            [DbTime::format($this->clock->now()), DbTime::format($this->clock->now()), $userId],
        );
        $result = [];
        foreach ($rows as $raw) {
            $row = $this->row($raw);
            $planOk = $row['plans'] === [] || ($planCode !== null && in_array($planCode, $row['plans'], true));
            $platformOk = $row['platforms'] === [] || array_intersect($row['platforms'], $platforms) !== [];
            if ($planOk && $platformOk) {
                $result[] = ['id' => (int) $row['id'], 'title' => (string) $row['title'], 'body' => (string) $row['body'], 'level' => (string) $row['level']];
            }
        }

        return $result;
    }

    /**
     * The person closes a notice (a critical one cannot be closed).
     */
    public function dismiss(int $id, int $userId): bool
    {
        $row = $this->db->select("SELECT id FROM announcements WHERE id = ? AND level <> 'critical'", [$id]);
        if ($row === []) {
            return false;
        }
        $this->db->execute('INSERT IGNORE INTO announcement_dismissals (announcement_id, user_id) VALUES (?, ?)', [$id, $userId]);

        return true;
    }
}
