<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Kernel\Database\Connection;
use App\Kernel\Http\RequestContext;
use App\Support\Clock;
use App\Support\DbTime;

/**
 * Append-only trail of security-relevant actions (who did what, from which IP). Never put secrets,
 * tokens or passwords into `$meta`.
 */
final class AuditLog
{
    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly RequestContext $context,
    ) {
    }

    /**
     * @param string $action dotted name such as `auth.login` or `auth.password.changed`
     * @param array<string, scalar|null> $meta
     */
    public function record(string $action, ?int $actorId, ?string $subjectType = null, ?string $subjectId = null, array $meta = [], ?int $workspaceId = null): void
    {
        $this->db->table('audit_log')->insert([
            'workspace_id' => $workspaceId,
            'actor_id' => $actorId,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'ip' => $this->context->request()?->ip(),
            'meta_json' => $meta === [] ? null : json_encode($meta, JSON_THROW_ON_ERROR),
            'created_at' => DbTime::format($this->clock->now()),
        ]);
    }
}
