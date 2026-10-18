<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Kernel\Database\Connection;

/** Tenant-only operational counters for the existing staff workspace screen; no tokens or content. */
final class StatsOperations
{
    public function __construct(private readonly Connection $db)
    {
    }
    /** @return array{pending:int,errors:int,snapshots:int,last:?string} */
    public function forWorkspace(int $workspace): array
    {
        $r = $this->db->select(
            'SELECT SUM(completed_at IS NULL) AS pending,SUM(error_code IS NOT NULL) AS errors,MAX(completed_at) AS last FROM analytics_tasks WHERE workspace_id=?',
            [$workspace]
        )[0];
        $s = $this->db->select('SELECT COUNT(*) AS n FROM post_stats_snapshots WHERE workspace_id=?', [$workspace])[0];
        return ['pending' => (int)$r['pending'],
            'errors' => (int)$r['errors'],
            'snapshots' => (int)$s['n'],
            'last' => is_string($r['last']) ? $r['last'] : null];
    }
}
