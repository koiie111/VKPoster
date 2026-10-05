<?php

declare(strict_types=1);

namespace App\Domain\Admin;

use App\Kernel\Database\Connection;
use App\Support\DbTime;
use DateTimeImmutable;

/**
 * Read model of the admin area: searchable lists and cards of users, workspaces, subscriptions and payments.
 * Staff look across every workspace on purpose, so these queries are not workspace-scoped: the routes that use them
 * are behind `RequireStaff` (superadmin, two-factor, unlock) and every change a staff member makes is audited elsewhere.
 * Dates are returned as UTC `DateTimeImmutable` (or null).
 */
final class AdminDirectory
{
    public const PAGE_SIZE = 25;

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int, pages: int, page: int}
     */
    public function users(string $query, int $page): array
    {
        [$where, $bindings] = $this->userFilter($query);
        $total = $this->count('SELECT COUNT(*) AS c FROM users u WHERE ' . $where, $bindings);
        [$page, $pages, $offset] = $this->paging($total, $page);
        $rows = $this->db->select(
            'SELECT u.id, u.email, u.name, u.status, u.is_superadmin, u.totp_enabled_at, u.email_verified_at, u.created_at, '
            . '(SELECT COUNT(*) FROM workspace_members m WHERE m.user_id = u.id) AS workspaces '
            . 'FROM users u WHERE ' . $where . ' ORDER BY u.id DESC LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . $offset,
            $bindings,
        );

        return ['rows' => array_map($this->dates(...), $rows), 'total' => $total, 'pages' => $pages, 'page' => $page];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function user(int $id): ?array
    {
        $rows = $this->db->select('SELECT id, email, name, status, is_superadmin, totp_enabled_at, email_verified_at, consent_version, consent_at, timezone, created_at FROM users WHERE id = ?', [$id]);

        return $rows === [] ? null : $this->dates($rows[0]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function userWorkspaces(int $userId): array
    {
        return array_map($this->dates(...), $this->db->select(
            'SELECT w.public_id, w.name, w.owner_id, m.role, p.name AS plan, s.status AS subscription '
            . 'FROM workspace_members m JOIN workspaces w ON w.id = m.workspace_id '
            . 'LEFT JOIN subscriptions s ON s.workspace_id = w.id LEFT JOIN plans p ON p.id = s.plan_id '
            . 'WHERE m.user_id = ? ORDER BY w.name',
            [$userId],
        ));
    }

    /**
     * What the person did recently (their own audit rows), newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function userActivity(int $userId, int $limit = 15): array
    {
        return array_map($this->dates(...), $this->db->select(
            'SELECT action, subject_type, subject_id, ip, created_at FROM audit_log WHERE actor_id = ? ORDER BY id DESC LIMIT ' . max(1, $limit),
            [$userId],
        ));
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int, pages: int, page: int}
     */
    public function workspaces(string $query, int $page): array
    {
        $where = '1 = 1';
        $bindings = [];
        $query = trim($query);
        if ($query !== '') {
            $like = '%' . self::escapeLike($query) . '%';
            $where = '(w.name LIKE ? OR w.public_id = ? OR o.email LIKE ?)';
            $bindings = [$like, strtoupper($query), $like];
        }
        $total = $this->count('SELECT COUNT(*) AS c FROM workspaces w JOIN users o ON o.id = w.owner_id WHERE ' . $where, $bindings);
        [$page, $pages, $offset] = $this->paging($total, $page);
        $rows = $this->db->select(
            'SELECT w.id, w.public_id, w.name, w.created_at, o.email AS owner_email, o.name AS owner_name, p.name AS plan, s.status AS subscription, '
            . '(SELECT COUNT(*) FROM workspace_members m WHERE m.workspace_id = w.id) AS members, '
            . '(SELECT COUNT(*) FROM channels c WHERE c.workspace_id = w.id) AS channels '
            . 'FROM workspaces w JOIN users o ON o.id = w.owner_id LEFT JOIN subscriptions s ON s.workspace_id = w.id LEFT JOIN plans p ON p.id = s.plan_id '
            . 'WHERE ' . $where . ' ORDER BY w.id DESC LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . $offset,
            $bindings,
        );

        return ['rows' => array_map($this->dates(...), $rows), 'total' => $total, 'pages' => $pages, 'page' => $page];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function workspace(string $publicId): ?array
    {
        $rows = $this->db->select(
            'SELECT w.id, w.public_id, w.name, w.timezone, w.is_personal, w.created_at, o.id AS owner_id, o.email AS owner_email, o.name AS owner_name, '
            . 'p.code AS plan_code, p.name AS plan, s.status AS subscription, s.period, s.current_period_end, s.trial_ends_at, s.cancel_at_period_end, s.last_failure '
            . 'FROM workspaces w JOIN users o ON o.id = w.owner_id LEFT JOIN subscriptions s ON s.workspace_id = w.id LEFT JOIN plans p ON p.id = s.plan_id WHERE w.public_id = ?',
            [$publicId],
        );

        return $rows === [] ? null : $this->dates($rows[0]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function workspaceMembers(int $workspaceId): array
    {
        return array_map($this->dates(...), $this->db->select(
            'SELECT u.id, u.email, u.name, u.status, m.role, m.joined_at FROM workspace_members m JOIN users u ON u.id = m.user_id WHERE m.workspace_id = ? ORDER BY m.joined_at',
            [$workspaceId],
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function workspaceChannels(int $workspaceId): array
    {
        return array_map($this->dates(...), $this->db->select(
            'SELECT platform, title, status, last_health_at, last_error FROM channels WHERE workspace_id = ? ORDER BY platform, title',
            [$workspaceId],
        ));
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int, pages: int, page: int}
     */
    public function payments(string $status, string $query, int $page, string $provider = '', ?DateTimeImmutable $from = null, ?DateTimeImmutable $to = null): array
    {
        $where = '1 = 1';
        $bindings = [];
        if ($provider !== '') {
            $where .= ' AND pay.provider = ?';
            $bindings[] = $provider;
        }
        if ($from !== null) {
            $where .= ' AND pay.created_at >= ?';
            $bindings[] = \App\Support\DbTime::format($from);
        }
        if ($to !== null) {
            $where .= ' AND pay.created_at < ?';
            $bindings[] = \App\Support\DbTime::format($to);
        }
        if (in_array($status, ['pending', 'succeeded', 'failed', 'refunded'], true)) {
            $where .= ' AND pay.status = ?';
            $bindings[] = $status;
        }
        $query = trim($query);
        if ($query !== '') {
            $where .= ' AND (pay.public_id = ? OR i.number = ? OR w.public_id = ? OR i.customer_email LIKE ?)';
            $bindings[] = strtoupper($query);
            $bindings[] = $query;
            $bindings[] = strtoupper($query);
            $bindings[] = '%' . self::escapeLike($query) . '%';
        }
        $from = 'FROM payments pay JOIN invoices i ON i.id = pay.invoice_id JOIN workspaces w ON w.id = pay.workspace_id JOIN plans p ON p.id = i.plan_id WHERE ' . $where;
        $total = $this->count('SELECT COUNT(*) AS c ' . $from, $bindings);
        [$page, $pages, $offset] = $this->paging($total, $page);
        $rows = $this->db->select(
            'SELECT pay.public_id, pay.provider, pay.status, pay.amount, pay.currency, pay.refunded_amount, pay.created_at, i.number, i.customer_email, i.kind, '
            . 'w.public_id AS workspace_public_id, w.name AS workspace_name, p.name AS plan ' . $from . ' ORDER BY pay.id DESC LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . $offset,
            $bindings,
        );

        return ['rows' => array_map($this->dates(...), $rows), 'total' => $total, 'pages' => $pages, 'page' => $page];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int, pages: int, page: int}
     */
    public function subscriptions(string $status, int $page): array
    {
        $where = '1 = 1';
        $bindings = [];
        if (in_array($status, ['trialing', 'active', 'past_due'], true)) {
            $where .= ' AND s.status = ?';
            $bindings[] = $status;
        } elseif ($status === 'canceled') {
            // Paid up to the end of the period and then not renewed.
            $where .= ' AND s.cancel_at_period_end = 1 AND s.current_period_end IS NOT NULL';
        } elseif ($status === 'free') {
            $where .= ' AND s.current_period_end IS NULL AND s.status = \'active\'';
        }
        $from = 'FROM subscriptions s JOIN workspaces w ON w.id = s.workspace_id JOIN plans p ON p.id = s.plan_id WHERE ' . $where;
        $total = $this->count('SELECT COUNT(*) AS c ' . $from, $bindings);
        [$page, $pages, $offset] = $this->paging($total, $page);
        $rows = $this->db->select(
            'SELECT s.public_id, s.status, s.period, s.price_amount, s.currency, s.current_period_end, s.trial_ends_at, s.cancel_at_period_end, s.last_failure, '
            . 'w.public_id AS workspace_public_id, w.name AS workspace_name, p.name AS plan ' . $from . ' ORDER BY s.updated_at DESC LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . $offset,
            $bindings,
        );

        return ['rows' => array_map($this->dates(...), $rows), 'total' => $total, 'pages' => $pages, 'page' => $page];
    }

    /**
     * One payment with its invoice, workspace and the owner's email, or null.
     *
     * @return array<string, mixed>|null
     */
    public function payment(string $publicId): ?array
    {
        $rows = $this->db->select(
            'SELECT pay.id, pay.public_id, pay.provider, pay.provider_payment_id, pay.status, pay.provider_status, pay.amount, pay.currency, pay.refunded_amount, pay.error_message, pay.created_at, pay.updated_at, '
            . 'i.number, i.kind, i.period, i.customer_email, i.paid_at, i.period_start, i.period_end, w.public_id AS workspace_public_id, w.name AS workspace_name, p.name AS plan '
            . 'FROM payments pay JOIN invoices i ON i.id = pay.invoice_id JOIN workspaces w ON w.id = pay.workspace_id JOIN plans p ON p.id = i.plan_id WHERE pay.public_id = ?',
            [strtoupper($publicId)],
        );

        return $rows === [] ? null : $this->dates($rows[0]);
    }

    /**
     * Journal entries of one payment (the money journal), newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function paymentLedger(string $publicId): array
    {
        return array_map($this->dates(...), $this->db->select(
            'SELECT e.ref_type, e.amount, e.currency, e.memo, e.created_at, a.code FROM ledger_entries e JOIN ledger_accounts a ON a.id = e.account_id WHERE e.ref_id = ? OR e.ref_id LIKE ? ORDER BY e.id DESC',
            [strtoupper($publicId), 'refund-' . strtoupper($publicId) . '%'],
        ));
    }

    /**
     * Notifications a provider sent about one payment.
     *
     * @return list<array<string, mixed>>
     */
    public function paymentWebhooks(string $provider, ?string $providerPaymentId): array
    {
        if ($providerPaymentId === null) {
            return [];
        }

        return array_map($this->dates(...), $this->db->select(
            'SELECT id, type, outcome, detail, payload, received_at FROM webhook_events WHERE provider = ? AND payment_ref = ? ORDER BY id DESC LIMIT 20',
            [$provider, $providerPaymentId],
        ));
    }

    /**
     * The webhook journal.
     *
     * @return array{rows: list<array<string, mixed>>, total: int, pages: int, page: int}
     */
    public function webhooks(string $provider, string $outcome, int $page): array
    {
        $where = '1 = 1';
        $bindings = [];
        if ($provider !== '') {
            $where .= ' AND provider = ?';
            $bindings[] = $provider;
        }
        if ($outcome !== '') {
            $where .= ' AND outcome = ?';
            $bindings[] = $outcome;
        }
        $total = $this->count('SELECT COUNT(*) AS c FROM webhook_events WHERE ' . $where, $bindings);
        [$page, $pages, $offset] = $this->paging($total, $page);
        $rows = $this->db->select('SELECT id, provider, event_id, type, payment_ref, outcome, detail, received_at FROM webhook_events WHERE ' . $where . ' ORDER BY id DESC LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . $offset, $bindings);

        return ['rows' => array_map($this->dates(...), $rows), 'total' => $total, 'pages' => $pages, 'page' => $page];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function webhook(int $id): ?array
    {
        $rows = $this->db->select(
            'SELECT e.id, e.provider, e.event_id, e.type, e.payment_ref, e.outcome, e.detail, e.payload, e.received_at, pay.public_id AS payment_public_id FROM webhook_events e '
            . 'LEFT JOIN payments pay ON pay.provider = e.provider AND pay.provider_payment_id = e.payment_ref WHERE e.id = ?',
            [$id],
        );

        return $rows === [] ? null : $this->dates($rows[0]);
    }

    /**
     * Escape `%`, `_` and `\` so a search text is matched literally by LIKE.
     */
    public static function escapeLike(string $text): string
    {
        return addcslashes($text, '%_\\');
    }

    /**
     * @return array{0: string, 1: list<int|string>}
     */
    private function userFilter(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return ['1 = 1', []];
        }
        $like = '%' . self::escapeLike($query) . '%';
        if (ctype_digit($query)) {
            return ['(u.id = ? OR u.email LIKE ? OR u.name LIKE ?)', [(int) $query, $like, $like]];
        }

        return ['(u.email LIKE ? OR u.name LIKE ?)', [$like, $like]];
    }

    /**
     * @param list<int|string> $bindings
     */
    private function count(string $sql, array $bindings): int
    {
        return (int) ($this->db->select($sql, $bindings)[0]['c'] ?? 0);
    }

    /**
     * @return array{0: int, 1: int, 2: int} page, pages, offset
     */
    private function paging(int $total, int $page): array
    {
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min(max(1, $page), $pages);

        return [$page, $pages, ($page - 1) * self::PAGE_SIZE];
    }

    /**
     * Turn the DATETIME columns of a row into `DateTimeImmutable` (UTC).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function dates(array $row): array
    {
        foreach ($row as $key => $value) {
            if ((str_ends_with($key, '_at') || $key === 'current_period_end') && (is_string($value) || $value === null)) {
                $row[$key] = $value === null ? null : (DbTime::parse($value) ?? new DateTimeImmutable('@0'));
            }
        }

        return $row;
    }
}
