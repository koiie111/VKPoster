<?php

declare(strict_types=1);

namespace App\Domain\Api;

use App\Domain\Audit\AuditLog;
use App\Domain\Billing\Entitlements;
use App\Domain\User\UserRepository;
use App\Domain\Workspace\{WorkspaceContext, WorkspaceRepository, Permissions};
use App\Kernel\Database\Connection;
use App\Support\{Clock, DbTime};
use Symfony\Component\Uid\Ulid;

/** Workspace API token lifecycle. Only SHA-256 of 256 random bits is persisted; live membership is rechecked. */
final class ApiTokens
{
    public const SCOPES = ['posts:read', 'posts:write', 'channels:read', 'media:write', 'stats:read'];

    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly WorkspaceRepository $workspaces, private readonly UserRepository $users, private readonly Permissions $permissions, private readonly Entitlements $plans, private readonly AuditLog $audit)
    {
    }

    public function manage(WorkspaceContext $ctx): void
    {
        if (!$this->permissions->allows($ctx->role, 'api.manage')) {
            throw new ApiException(403, 'forbidden', 'Управление API недоступно.');
        }
        $this->plans->assertFeature($ctx->workspaceId, 'api', 'API и вебхуки');
    }

    /** @param list<string> $scopes
     * @return array{id:string,secret:string}
     */
    public function create(WorkspaceContext $ctx, string $name, array $scopes, ?\DateTimeImmutable $expires = null): array
    {
        $this->manage($ctx);
        if (trim($name) === '' || mb_strlen($name) > 100 || $scopes === [] || array_diff($scopes, self::SCOPES) !== [] || ($expires !== null && $expires <= $this->clock->now())) {
            throw new ApiException(422, 'validation_error', 'Проверьте название, права и срок токена.');
        }
        $secret = 'vkp_' . bin2hex(random_bytes(32));
        $id = (string) new Ulid();
        $this->db->table('api_tokens')->insert(['public_id' => $id, 'workspace_id' => $ctx->workspaceId, 'created_by' => $ctx->userId, 'name' => trim($name), 'scopes_json' => json_encode(array_values(array_unique($scopes)), JSON_THROW_ON_ERROR), 'token_hash' => hash('sha256', $secret), 'expires_at' => $expires === null ? null : DbTime::format($expires), 'created_at' => DbTime::format($this->clock->now())]);
        $this->audit->record('api.token_created', $ctx->userId, 'api_token', $id, [], $ctx->workspaceId);
        return ['id' => $id, 'secret' => $secret];
    }

    /** @return list<array<string,mixed>> */
    public function all(WorkspaceContext $ctx): array
    {
        $this->manage($ctx);
        return $this->db->select('SELECT public_id,name,scopes_json,expires_at,revoked_at,last_used_at,created_at FROM api_tokens WHERE workspace_id=? ORDER BY id DESC', [$ctx->workspaceId]);
    }

    public function revoke(WorkspaceContext $ctx, string $id): void
    {
        $this->manage($ctx);
        if ($this->db->select('SELECT id FROM api_tokens WHERE workspace_id=? AND public_id=?', [$ctx->workspaceId, $id]) === []) {
            throw new ApiException(404, 'not_found', 'Токен не найден.');
        }
        $this->db->execute('UPDATE api_tokens SET revoked_at=COALESCE(revoked_at,?) WHERE workspace_id=? AND public_id=?', [DbTime::format($this->clock->now()), $ctx->workspaceId, $id]);
        $this->audit->record('api.token_revoked', $ctx->userId, 'api_token', $id, [], $ctx->workspaceId);
    }

    public function authenticate(#[\SensitiveParameter] string $secret): ApiIdentity
    {
        if (preg_match('/^vkp_[a-f0-9]{64}$/D', $secret) !== 1) {
            throw new ApiException(401, 'unauthorized', 'Требуется действующий Bearer token.');
        }
        $now = DbTime::format($this->clock->now());
        $row = $this->db->select('SELECT * FROM api_tokens WHERE token_hash=? AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at>?)', [hash('sha256', $secret), $now])[0] ?? null;
        $member = $row === null ? null : $this->workspaces->membership((int) $row['workspace_id'], (int) $row['created_by']);
        $workspace = $row === null ? null : $this->workspaces->findById((int) $row['workspace_id']);
        $user = $row === null ? null : $this->users->find((int) $row['created_by']);
        if ($row === null || $member === null || $workspace === null || $user === null || $user->isBlocked()) {
            throw new ApiException(401, 'unauthorized', 'Требуется действующий Bearer token.');
        }
        $this->plans->assertFeature($workspace->id, 'api', 'API и вебхуки');
        $this->db->execute('UPDATE api_tokens SET last_used_at=? WHERE id=? AND (last_used_at IS NULL OR last_used_at<?)', [$now, $row['id'], DbTime::format($this->clock->now()->modify('-5 minutes'))]);
        $scopes = json_decode((string) $row['scopes_json'], true, 8, JSON_THROW_ON_ERROR);
        return new ApiIdentity((int) $row['id'], WorkspaceContext::from($workspace, $member), array_values(array_filter($scopes, 'is_string')));
    }
}
