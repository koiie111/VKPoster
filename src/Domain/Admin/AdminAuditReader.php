<?php

declare(strict_types=1);

namespace App\Domain\Admin;

use App\Kernel\Database\Connection;
use App\Support\DbTime;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Reads the audit journal for the back office: what staff did (actions `admin.*`) or everything, with filters and an export.
 * The journal is global here on purpose; the routes are behind the `audit.view` permission (owner only).
 */
final class AdminAuditReader
{
    public const PAGE_SIZE = 50;
    public const EXPORT_LIMIT = 50000;

    /** @var array<string, string> */
    private const LABELS = [
        'admin.unlocked' => 'Вход в админку',
        'admin.unlock_failed' => 'Неверный код при входе в админку',
        'admin.denied' => 'Отказано в доступе',
        'admin.request' => 'Запрос на изменение',
        'admin.user_blocked' => 'Пользователь заблокирован',
        'admin.user_unblocked' => 'Пользователь разблокирован',
        'admin.user_signed_out' => 'Пользователь разлогинен везде',
        'admin.user_email_verified' => 'Почта подтверждена вручную',
        'admin.user_2fa_reset' => 'Сброшена двухфакторная защита',
        'admin.user_note_added' => 'Добавлена заметка о пользователе',
        'admin.user_viewed' => 'Открыта карточка пользователя',
        'admin.users_exported' => 'Выгружен список пользователей',
        'admin.impersonation_started' => 'Вход под пользователем',
        'admin.impersonation_stopped' => 'Выход из-под пользователя',
        'admin.grant' => 'Ручное начисление',
        'admin.plan_updated' => 'Изменён тариф',
        'admin.refund' => 'Возврат платежа',
        'admin.payment_reconciled' => 'Платёж сверен с провайдером',
        'admin.finance_exported' => 'Выгрузка для бухгалтерии',
        'admin.staff_assigned' => 'Назначена роль сотрудника',
        'admin.staff_removed' => 'Роль сотрудника снята',
        'admin.settings_changed' => 'Изменены настройки сайта',
        'admin.audit_exported' => 'Выгружен журнал аудита',
        'admin.data_exported' => 'Выгрузка данных пользователя (152-ФЗ)',
        'admin.account_anonymized' => 'Аккаунт анонимизирован',
    ];

    public function __construct(private readonly Connection $db)
    {
    }

    public static function label(string $action): string
    {
        return self::LABELS[$action] ?? $action;
    }

    /**
     * Known actions for the filter drop-down: the labelled ones first.
     *
     * @return array<string, string>
     */
    public static function actions(): array
    {
        return self::LABELS;
    }

    /**
     * @param array{scope?: string, action?: string, actor?: string, subject?: string, from?: ?DateTimeImmutable, to?: ?DateTimeImmutable} $filters
     *   scope `admin` (default, only actions of staff) or `all`; actor is an id or part of an email; subject matches the subject id exactly
     * @return array{rows: list<array<string, mixed>>, total: int, pages: int, page: int}
     */
    public function page(array $filters, int $page): array
    {
        [$where, $bindings] = $this->filter($filters);
        $total = (int) ($this->db->select('SELECT COUNT(*) AS c FROM audit_log a WHERE ' . $where, $bindings)[0]['c'] ?? 0);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min(max(1, $page), $pages);
        $rows = $this->db->select(
            'SELECT a.id, a.action, a.actor_id, a.subject_type, a.subject_id, a.ip, a.meta_json, a.created_at, u.name AS actor_name, u.email AS actor_email '
            . 'FROM audit_log a LEFT JOIN users u ON u.id = a.actor_id WHERE ' . $where . ' ORDER BY a.id DESC LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . (($page - 1) * self::PAGE_SIZE),
            $bindings,
        );

        return ['rows' => array_map($this->row(...), $rows), 'total' => $total, 'pages' => $pages, 'page' => $page];
    }

    /**
     * Rows for the export, newest first, at most `EXPORT_LIMIT`.
     *
     * @param array{scope?: string, action?: string, actor?: string, subject?: string, from?: ?DateTimeImmutable, to?: ?DateTimeImmutable} $filters
     * @return \Generator<int, array<string, mixed>>
     */
    public function export(array $filters): \Generator
    {
        [$where, $bindings] = $this->filter($filters);
        $lastId = null;
        $sent = 0;
        while ($sent < self::EXPORT_LIMIT) {
            $rows = $this->db->select(
                'SELECT a.id, a.action, a.actor_id, a.subject_type, a.subject_id, a.ip, a.meta_json, a.created_at, u.name AS actor_name, u.email AS actor_email '
                . 'FROM audit_log a LEFT JOIN users u ON u.id = a.actor_id WHERE ' . $where . ($lastId === null ? '' : ' AND a.id < ' . $lastId)
                . ' ORDER BY a.id DESC LIMIT 1000',
                $bindings,
            );
            if ($rows === []) {
                return;
            }
            foreach ($rows as $row) {
                yield $this->row($row);
                $lastId = (int) $row['id'];
                ++$sent;
            }
        }
    }

    /**
     * @param array{scope?: string, action?: string, actor?: string, subject?: string, from?: ?DateTimeImmutable, to?: ?DateTimeImmutable} $filters
     * @return array{0: string, 1: list<int|string>}
     */
    private function filter(array $filters): array
    {
        $where = ($filters['scope'] ?? 'admin') === 'all' ? '1 = 1' : 'a.action LIKE \'admin.%\'';
        $bindings = [];
        $action = $filters['action'] ?? '';
        if ($action !== '') {
            $where .= ' AND a.action = ?';
            $bindings[] = $action;
        }
        $actor = trim($filters['actor'] ?? '');
        if ($actor !== '') {
            if (ctype_digit($actor)) {
                $where .= ' AND a.actor_id = ?';
                $bindings[] = (int) $actor;
            } else {
                $where .= ' AND a.actor_id IN (SELECT id FROM users WHERE email LIKE ?)';
                $bindings[] = '%' . AdminDirectory::escapeLike($actor) . '%';
            }
        }
        $subject = trim($filters['subject'] ?? '');
        if ($subject !== '') {
            $where .= ' AND a.subject_id = ?';
            $bindings[] = $subject;
        }
        if (($filters['from'] ?? null) !== null) {
            $where .= ' AND a.created_at >= ?';
            $bindings[] = DbTime::format($filters['from']);
        }
        if (($filters['to'] ?? null) !== null) {
            $where .= ' AND a.created_at < ?';
            $bindings[] = DbTime::format($filters['to']);
        }

        return [$where, $bindings];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function row(array $row): array
    {
        $meta = is_string($row['meta_json']) ? json_decode($row['meta_json'], true) : [];
        $meta = is_array($meta) ? $meta : [];

        return [
            'id' => (int) $row['id'],
            'action' => (string) $row['action'],
            'label' => self::label((string) $row['action']),
            'actor_id' => isset($row['actor_id']) ? (int) $row['actor_id'] : null,
            'actor_name' => is_string($row['actor_name']) ? $row['actor_name'] : null,
            'actor_email' => is_string($row['actor_email']) ? $row['actor_email'] : null,
            'subject_type' => is_string($row['subject_type']) ? $row['subject_type'] : null,
            'subject_id' => is_string($row['subject_id']) ? $row['subject_id'] : null,
            'ip' => is_string($row['ip']) ? $row['ip'] : null,
            'meta' => $meta,
            'details' => self::describe($meta),
            'created_at' => DbTime::parse($row['created_at']) ?? new DateTimeImmutable('@0', new DateTimeZone('UTC')),
        ];
    }

    /**
     * One line "key: value" for the table (before/after pairs read as `было → стало`).
     *
     * @param array<string, mixed> $meta
     */
    public static function describe(array $meta): string
    {
        $parts = [];
        foreach ($meta as $key => $value) {
            if (is_array($value)) {
                $value = (string) json_encode($value, JSON_UNESCAPED_UNICODE);
            }
            $parts[] = $key . ': ' . (is_bool($value) ? ($value ? 'да' : 'нет') : (is_scalar($value) ? (string) $value : ''));
        }

        return implode('; ', $parts);
    }
}
