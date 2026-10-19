<?php

declare(strict_types=1);

namespace App\Domain\Api;

use App\Kernel\Database\Connection;
use App\Support\{Clock, DbTime};

/** Tenant operational counters for existing staff workspace inspection; never exposes secrets or payloads. */
final class ApiOperations
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    /** @return array{tokens:int,hooks:int,pending:int,failed:int,last:?string} */
    public function forWorkspace(int $workspace): array
    {
        $tokens = $this->db->select('SELECT COUNT(*) AS n FROM api_tokens WHERE workspace_id=? AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at>?)', [$workspace, DbTime::format($this->clock->now())])[0];
        $hooks = $this->db->select('SELECT COUNT(*) AS n FROM webhook_subscriptions WHERE workspace_id=? AND enabled=1', [$workspace])[0];
        $deliveries = $this->db->select("SELECT SUM(status IN ('pending','processing')) AS pending,SUM(status='failed') AS failed,MAX(completed_at) AS last FROM webhook_deliveries WHERE workspace_id=?", [$workspace])[0];
        return ['tokens' => (int) $tokens['n'], 'hooks' => (int) $hooks['n'], 'pending' => (int) $deliveries['pending'], 'failed' => (int) $deliveries['failed'], 'last' => is_string($deliveries['last']) ? $deliveries['last'] : null];
    }
}
