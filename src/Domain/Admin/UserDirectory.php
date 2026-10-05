<?php

declare(strict_types=1);

namespace App\Domain\Admin;

use App\Kernel\Database\Connection;
use App\Support\DbTime;
use DateTimeImmutable;

/**
 * Staff's view of people: a searchable, filterable, sortable list (also for the CSV export) and everything known about one person for
 * their card. Staff look across all workspaces on purpose, so nothing here is workspace-scoped; the routes are behind the staff permissions.
 */
final class UserDirectory
{
    public const PAGE_SIZE = 25;
    public const EXPORT_LIMIT = 50000;

    /** @var array<string, string> sort key => ORDER BY expression (a whitelist: the key never reaches SQL as typed) */
    private const SORTS = [
        'created' => 'u.id',
        'name' => 'u.name',
        'email' => 'u.email',
        'workspaces' => 'workspaces',
        'activity' => 'last_active',
    ];

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Search by an id, an address or a part of it, a name, `@domain.ru` (everybody from a mail domain) or the id a person has in VK / Telegram.
     *
     * @param array{q?: string, plan?: string, status?: string, from?: ?DateTimeImmutable, to?: ?DateTimeImmutable, source?: string, activity?: string, sort?: string, dir?: string} $filters
     * @return array{rows: list<array<string, mixed>>, total: int, pages: int, page: int}
     */
    public function page(array $filters, int $page): array
    {
        [$where, $bindings] = $this->where($filters);
        $total = (int) ($this->db->select('SELECT COUNT(*) AS c FROM users u LEFT JOIN user_attribution a ON a.user_id = u.id WHERE ' . $where, $bindings)[0]['c'] ?? 0);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min(max(1, $page), $pages);
        $rows = $this->db->select($this->select($filters) . ' LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . (($page - 1) * self::PAGE_SIZE), $bindings);

        return ['rows' => array_map($this->row(...), $rows), 'total' => $total, 'pages' => $pages, 'page' => $page];
    }

    /**
     * @param array{q?: string, plan?: string, status?: string, from?: ?DateTimeImmutable, to?: ?DateTimeImmutable, source?: string, activity?: string, sort?: string, dir?: string} $filters
     * @return \Generator<int, array<string, mixed>>
     */
    public function export(array $filters): \Generator
    {
        [, $bindings] = $this->where($filters);
        $offset = 0;
        while ($offset < self::EXPORT_LIMIT) {
            $rows = $this->db->select($this->select($filters) . ' LIMIT 1000 OFFSET ' . $offset, $bindings);
            if ($rows === []) {
                return;
            }
            foreach ($rows as $row) {
                yield $this->row($row);
            }
            $offset += 1000;
        }
    }

    /**
     * Plans for the filter drop-down.
     *
     * @return array<string, string> code => name
     */
    public function plans(): array
    {
        $plans = [];
        foreach ($this->db->select('SELECT code, name FROM plans ORDER BY sort') as $row) {
            $plans[(string) $row['code']] = (string) $row['name'];
        }

        return $plans;
    }

    /**
     * @return list<string> traffic sources seen at sign-up, most common first
     */
    public function sources(): array
    {
        $found = [];
        foreach ($this->db->select("SELECT COALESCE(NULLIF(utm_source, ''), NULLIF(referrer, ''), 'direct') AS src, COUNT(*) AS c FROM user_attribution GROUP BY src ORDER BY c DESC LIMIT 30") as $row) {
            $found[] = (string) $row['src'];
        }

        return $found;
    }

    /**
     * One person as a list row (plan, source, last activity), or null when there is no such person.
     *
     * @return array<string, mixed>|null
     */
    public function summary(int $id): ?array
    {
        $rows = $this->db->select($this->columns() . ' WHERE u.id = ?', [$id]);

        return $rows === [] ? null : $this->row($rows[0]);
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function select(array $filters): string
    {
        [$where] = $this->where($filters);
        $sort = self::SORTS[(string) ($filters['sort'] ?? '')] ?? self::SORTS['created'];
        $dir = ($filters['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

        return $this->columns() . ' WHERE ' . $where . ' ORDER BY ' . $sort . ' ' . $dir . ', u.id DESC';
    }

    private function columns(): string
    {
        return 'SELECT u.id, u.email, u.name, u.status, u.block_reason, u.is_superadmin, u.totp_enabled_at, u.email_verified_at, u.created_at, '
            . "COALESCE(NULLIF(a.utm_source, ''), NULLIF(a.referrer, ''), 'direct') AS source, "
            . '(SELECT COUNT(*) FROM workspace_members m WHERE m.user_id = u.id) AS workspaces, '
            . '(SELECT MAX(d.day) FROM user_activity_days d WHERE d.user_id = u.id) AS last_active, '
            . '(SELECT p.name FROM workspaces w JOIN subscriptions s ON s.workspace_id = w.id JOIN plans p ON p.id = s.plan_id WHERE w.owner_id = u.id ORDER BY p.sort DESC LIMIT 1) AS plan, '
            . '(SELECT COUNT(*) FROM staff_members sm WHERE sm.user_id = u.id) AS staff_role '
            . 'FROM users u LEFT JOIN user_attribution a ON a.user_id = u.id';
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: list<int|string>}
     */
    private function where(array $filters): array
    {
        $where = '1 = 1';
        $bindings = [];
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . AdminDirectory::escapeLike($q) . '%';
            if (str_starts_with($q, '@') && strlen($q) > 1) {
                $where .= ' AND u.email LIKE ?';
                $bindings[] = '%' . AdminDirectory::escapeLike($q);
            } elseif (ctype_digit($q)) {
                $where .= ' AND (u.id = ? OR u.email LIKE ? OR u.name LIKE ? OR EXISTS (SELECT 1 FROM user_identities i WHERE i.user_id = u.id AND i.provider_user_id = ?))';
                array_push($bindings, (int) $q, $like, $like, $q);
            } else {
                $where .= ' AND (u.email LIKE ? OR u.name LIKE ? OR EXISTS (SELECT 1 FROM user_identities i WHERE i.user_id = u.id AND (i.email LIKE ? OR i.display_name LIKE ?)))';
                array_push($bindings, $like, $like, $like, $like);
            }
        }
        $plan = (string) ($filters['plan'] ?? '');
        if ($plan !== '') {
            $where .= ' AND EXISTS (SELECT 1 FROM workspaces w JOIN subscriptions s ON s.workspace_id = w.id JOIN plans p ON p.id = s.plan_id WHERE w.owner_id = u.id AND p.code = ?)';
            $bindings[] = $plan;
        }
        $status = (string) ($filters['status'] ?? '');
        if (in_array($status, ['active', 'blocked'], true)) {
            $where .= ' AND u.status = ?';
            $bindings[] = $status;
        } elseif ($status === 'staff') {
            $where .= ' AND (u.is_superadmin = 1 OR EXISTS (SELECT 1 FROM staff_members sm WHERE sm.user_id = u.id))';
        } elseif ($status === 'unverified') {
            $where .= ' AND u.email_verified_at IS NULL';
        }
        if (($filters['from'] ?? null) instanceof DateTimeImmutable) {
            $where .= ' AND u.created_at >= ?';
            $bindings[] = DbTime::format($filters['from']);
        }
        if (($filters['to'] ?? null) instanceof DateTimeImmutable) {
            $where .= ' AND u.created_at < ?';
            $bindings[] = DbTime::format($filters['to']);
        }
        $source = (string) ($filters['source'] ?? '');
        if ($source !== '') {
            $where .= " AND COALESCE(NULLIF(a.utm_source, ''), NULLIF(a.referrer, ''), 'direct') = ?";
            $bindings[] = $source;
        }
        $activity = (string) ($filters['activity'] ?? '');
        $since = gmdate('Y-m-d', time() - 30 * 86400);
        if ($activity === 'active') {
            $where .= ' AND EXISTS (SELECT 1 FROM user_activity_days d WHERE d.user_id = u.id AND d.day >= ?)';
            $bindings[] = $since;
        } elseif ($activity === 'inactive') {
            $where .= ' AND NOT EXISTS (SELECT 1 FROM user_activity_days d WHERE d.user_id = u.id AND d.day >= ?)';
            $bindings[] = $since;
        }

        return [$where, $bindings];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function row(array $row): array
    {
        $row['created_at'] = DbTime::parse($row['created_at']);
        $row['email_verified_at'] = DbTime::parse($row['email_verified_at']);
        $row['totp_enabled_at'] = DbTime::parse($row['totp_enabled_at']);
        $row['last_active'] = is_string($row['last_active']) ? new DateTimeImmutable($row['last_active']) : null;
        $row['is_staff'] = (int) $row['is_superadmin'] === 1 || (int) $row['staff_role'] > 0;

        return $row;
    }

    /**
     * Sign-in methods linked to the account (provider, the name it shows, when linked and last used). Never tokens.
     *
     * @return list<array<string, mixed>>
     */
    public function identities(int $userId): array
    {
        return array_map(static function (array $r): array {
            $r['linked_at'] = DbTime::parse($r['linked_at']);
            $r['last_login_at'] = DbTime::parse($r['last_login_at']);

            return $r;
        }, $this->db->select('SELECT provider, provider_user_id, email, display_name, linked_at, last_login_at FROM user_identities WHERE user_id = ? ORDER BY linked_at', [$userId]));
    }

    /**
     * Workspaces the person works in (any role), with the plan.
     *
     * @return list<array<string, mixed>>
     */
    public function memberships(int $userId): array
    {
        return array_map(static fn (array $r): array => $r, $this->db->select(
            'SELECT w.public_id, w.name, w.owner_id, m.role, p.name AS plan, s.status AS subscription '
            . 'FROM workspace_members m JOIN workspaces w ON w.id = m.workspace_id '
            . 'LEFT JOIN subscriptions s ON s.workspace_id = w.id LEFT JOIN plans p ON p.id = s.plan_id WHERE m.user_id = ? ORDER BY w.name',
            [$userId],
        ));
    }

    /**
     * Devices that are signed in now.
     *
     * @return list<array<string, mixed>>
     */
    public function sessions(int $userId): array
    {
        return array_map(static function (array $r): array {
            $r['created_at'] = DbTime::parse($r['created_at']);
            $r['last_seen_at'] = DbTime::parse($r['last_seen_at']);

            return $r;
        }, $this->db->select('SELECT ip, user_agent, created_at, last_seen_at FROM user_sessions WHERE user_id = ? AND revoked_at IS NULL ORDER BY last_seen_at DESC LIMIT 20', [$userId]));
    }

    /**
     * Workspaces the person owns, with the plan, and what they hold in the wallet is added by the caller from the ledger.
     *
     * @return list<array<string, mixed>>
     */
    public function ownedWorkspaces(int $userId): array
    {
        return array_map(static function (array $r): array {
            $r['current_period_end'] = DbTime::parse($r['current_period_end']);
            $r['trial_ends_at'] = DbTime::parse($r['trial_ends_at']);

            return $r;
        }, $this->db->select(
            'SELECT w.id, w.public_id, w.name, p.code AS plan_code, p.name AS plan, s.status AS subscription, s.period, s.current_period_end, s.trial_ends_at, s.cancel_at_period_end '
            . 'FROM workspaces w LEFT JOIN subscriptions s ON s.workspace_id = w.id LEFT JOIN plans p ON p.id = s.plan_id WHERE w.owner_id = ? ORDER BY w.id',
            [$userId],
        ));
    }

    /**
     * The person's payments (through the workspaces they own), newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function payments(int $userId, int $limit = 10): array
    {
        return array_map(static function (array $r): array {
            $r['created_at'] = DbTime::parse($r['created_at']);

            return $r;
        }, $this->db->select(
            'SELECT pay.public_id, pay.provider, pay.status, pay.amount, pay.currency, pay.refunded_amount, pay.created_at, i.number, p.name AS plan '
            . 'FROM payments pay JOIN invoices i ON i.id = pay.invoice_id JOIN plans p ON p.id = i.plan_id JOIN workspaces w ON w.id = pay.workspace_id '
            . 'WHERE w.owner_id = ? ORDER BY pay.id DESC LIMIT ' . max(1, $limit),
            [$userId],
        ));
    }

    /**
     * Channels of the workspaces the person owns.
     *
     * @return list<array<string, mixed>>
     */
    public function channels(int $userId): array
    {
        return array_map(static function (array $r): array {
            $r['last_health_at'] = DbTime::parse($r['last_health_at']);

            return $r;
        }, $this->db->select(
            'SELECT c.platform, c.title, c.status, c.last_error, c.last_health_at, w.name AS workspace, w.public_id AS workspace_public_id FROM channels c JOIN workspaces w ON w.id = c.workspace_id WHERE w.owner_id = ? ORDER BY c.id DESC LIMIT 30',
            [$userId],
        ));
    }

    /**
     * Latest publications of the person's workspaces (failures first would hide the good news, so simply the newest).
     *
     * @return list<array<string, mixed>>
     */
    public function publications(int $userId, int $limit = 10): array
    {
        return array_map(static function (array $r): array {
            $r['due_at'] = DbTime::parse($r['due_at']);

            return $r;
        }, $this->db->select(
            'SELECT p.status, p.error_code, p.error_message, p.due_at, v.platform, v.channel_name, w.public_id AS workspace_public_id FROM publications p JOIN post_variants v ON v.id = p.variant_id JOIN workspaces w ON w.id = p.workspace_id '
            . 'WHERE w.owner_id = ? ORDER BY p.id DESC LIMIT ' . max(1, $limit),
            [$userId],
        ));
    }

    /**
     * Private notes of staff about the person.
     *
     * @return list<array<string, mixed>>
     */
    public function notes(int $userId): array
    {
        return array_map(static function (array $r): array {
            $r['created_at'] = DbTime::parse($r['created_at']);

            return $r;
        }, $this->db->select('SELECT n.body, n.created_at, u.name AS author FROM admin_notes n LEFT JOIN users u ON u.id = n.author_id WHERE n.user_id = ? ORDER BY n.id DESC LIMIT 50', [$userId]));
    }

    /**
     * Audit rows about the person: what they did and what staff did to them.
     *
     * @return list<array<string, mixed>>
     */
    public function journal(int $userId, int $limit = 20): array
    {
        return array_map(static function (array $r): array {
            $r['created_at'] = DbTime::parse($r['created_at']);
            $r['label'] = AdminAuditReader::label((string) $r['action']);

            return $r;
        }, $this->db->select(
            'SELECT action, actor_id, subject_type, subject_id, ip, created_at FROM audit_log WHERE actor_id = ? OR (subject_type = \'user\' AND subject_id = ?) ORDER BY id DESC LIMIT ' . max(1, $limit),
            [$userId, (string) $userId],
        ));
    }

    /**
     * Where the person came from at sign-up (UTM tags, referring site, first page); null when nothing was recorded.
     *
     * @return array<string, mixed>|null
     */
    public function attribution(int $userId): ?array
    {
        $rows = $this->db->select('SELECT utm_source, utm_medium, utm_campaign, referrer, landing FROM user_attribution WHERE user_id = ?', [$userId]);

        return $rows === [] ? null : $rows[0];
    }
}
