<?php

declare(strict_types=1);

namespace App\Domain\Approval;

use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;

/** Staff read model: tenant-specific counters only, never comments, guest capability or PIN. */
final class ApprovalStats
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }
    /** @return array{pending:int,approved:int,changes:int,comments:int,links:int} */
    public function forWorkspace(int $workspace): array
    {
        $r = ['pending' => 0,'approved' => 0,'changes' => 0,'comments' => 0,'links' => 0];
        foreach ($this->db->select("SELECT status,COUNT(*) AS c FROM posts WHERE workspace_id=? AND status IN ('pending_approval','approved','changes_requested') GROUP BY status", [$workspace]) as $row) {
            $key = match($row['status']) {
                'approved' => 'approved','changes_requested' => 'changes',default => 'pending'
            };
            $r[$key] = (int)$row['c'];
        }
        $r['comments'] = (int)$this->db->select('SELECT COUNT(*) AS c FROM post_comments WHERE workspace_id=?', [$workspace])[0]['c'];
        $r['links'] = (int)$this->db->select('SELECT COUNT(*) AS c FROM guest_links WHERE workspace_id=? AND revoked_at IS NULL AND expires_at>?', [$workspace,DbTime::format($this->clock->now())])[0]['c'];
        return $r;
    }
}
