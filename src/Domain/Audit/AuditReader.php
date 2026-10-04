<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Domain\Workspace\WorkspaceContext;
use App\Domain\Workspace\WorkspaceScopedRepository;
use App\Support\DbTime;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Reads the audit journal of one workspace with filters (group of actions, person, period) and paging.
 * Only rows tagged with the context's workspace are visible; sign-in events live in the personal journal.
 */
final class AuditReader extends WorkspaceScopedRepository
{
    public const PAGE_SIZE = 50;

    /**
     * @param string|null $group one of `AuditActions::groups()` keys
     * @param int|null $actorId user id of the person who acted
     * @param DateTimeImmutable|null $from inclusive start (UTC)
     * @param DateTimeImmutable|null $to exclusive end (UTC)
     * @return array{rows: list<array{id: int, action: string, label: string, actor_id: ?int, actor_name: ?string, subject_type: ?string, subject_id: ?string, ip: ?string, meta: array<string, mixed>, created_at: DateTimeImmutable}>, total: int, pages: int, page: int}
     */
    public function page(WorkspaceContext $context, ?string $group, ?int $actorId, ?DateTimeImmutable $from, ?DateTimeImmutable $to, int $page): array
    {
        $where = 'a.workspace_id = ?';
        $bindings = [$context->workspaceId];
        if ($group !== null && isset(AuditActions::groups()[$group])) {
            $where .= ' AND a.action LIKE ?';
            $bindings[] = $group . '.%';
        }
        if ($actorId !== null) {
            $where .= ' AND a.actor_id = ?';
            $bindings[] = $actorId;
        }
        if ($from !== null) {
            $where .= ' AND a.created_at >= ?';
            $bindings[] = DbTime::format($from);
        }
        if ($to !== null) {
            $where .= ' AND a.created_at < ?';
            $bindings[] = DbTime::format($to);
        }
        $total = (int) ($this->db->select('SELECT COUNT(*) AS c FROM audit_log a WHERE ' . $where, $bindings)[0]['c'] ?? 0);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min(max(1, $page), $pages);
        $rows = $this->db->select(
            'SELECT a.id, a.action, a.actor_id, a.subject_type, a.subject_id, a.ip, a.meta_json, a.created_at, u.name AS actor_name FROM audit_log a LEFT JOIN users u ON u.id = a.actor_id WHERE ' . $where
            . ' ORDER BY a.id DESC LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . (($page - 1) * self::PAGE_SIZE),
            $bindings,
        );
        $out = [];
        foreach ($rows as $row) {
            $meta = is_string($row['meta_json']) ? json_decode($row['meta_json'], true) : [];
            $out[] = [
                'id' => (int) $row['id'],
                'action' => (string) $row['action'],
                'label' => AuditActions::label((string) $row['action']),
                'actor_id' => isset($row['actor_id']) ? (int) $row['actor_id'] : null,
                'actor_name' => is_string($row['actor_name']) ? $row['actor_name'] : null,
                'subject_type' => is_string($row['subject_type']) ? $row['subject_type'] : null,
                'subject_id' => is_string($row['subject_id']) ? $row['subject_id'] : null,
                'ip' => is_string($row['ip']) ? $row['ip'] : null,
                'meta' => is_array($meta) ? $meta : [],
                'created_at' => DbTime::parse($row['created_at']) ?? new DateTimeImmutable('@0', new DateTimeZone('UTC')),
            ];
        }

        return ['rows' => $out, 'total' => $total, 'pages' => $pages, 'page' => $page];
    }

    /**
     * People who appear as actors in this workspace's journal, for the filter drop-down.
     *
     * @return array<int, string> user id => name
     */
    public function actors(WorkspaceContext $context): array
    {
        $rows = $this->db->select(
            'SELECT DISTINCT a.actor_id, u.name FROM audit_log a JOIN users u ON u.id = a.actor_id WHERE a.workspace_id = ? ORDER BY u.name ASC',
            [$context->workspaceId],
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['actor_id']] = (string) $row['name'];
        }

        return $out;
    }
}
