<?php

declare(strict_types=1);

namespace App\Domain\Webhook;

use App\Domain\Api\{ApiException, ApiTokens};
use App\Domain\Audit\AuditLog;
use App\Domain\Workspace\{WorkspaceContext, ChannelAccessRepository};
use App\Kernel\Database\Connection;
use App\Kernel\HttpClient\SsrfGuard;
use App\Kernel\Security\Crypto;
use App\Support\{Clock, DbTime};
use Symfony\Component\Uid\Ulid;

/** Workspace subscription controls; signing secrets are encrypted and returned only on creation. */
final class Webhooks
{
    public function __construct(private readonly Connection $db, private readonly ApiTokens $tokens, private readonly SsrfGuard $guard, private readonly Crypto $crypto, private readonly Clock $clock, private readonly AuditLog $audit, private readonly ChannelAccessRepository $access)
    {
    }

    public function manage(WorkspaceContext $ctx): void
    {
        $this->tokens->manage($ctx);
        if ($this->access->allowed($ctx, $ctx->userId) !== null) {
            throw new ApiException(403, 'forbidden', 'Вебхуки пространства доступны администраторам с доступом ко всем каналам.');
        }
    }

    /** @param list<string> $events
     * @return array{id:string,secret:string}
     */
    public function create(WorkspaceContext $ctx, string $url, array $events): array
    {
        $this->manage($ctx);
        if (strlen($url) > 2000 || $events === [] || array_diff($events, [...WebhookOutbox::EVENTS, 'approval.*']) !== []) {
            throw new ApiException(422, 'validation_error', 'Проверьте URL и события.');
        }
        try {
            $this->guard->assertSafe($url);
        } catch (\App\Kernel\Exception\SsrfException) {
            throw new ApiException(422, 'unsafe_url', 'Нужен публичный HTTP(S) URL.');
        }
        $id = (string) new Ulid();
        $secret = bin2hex(random_bytes(32));
        $this->db->table('webhook_subscriptions')->insert(['public_id' => $id, 'workspace_id' => $ctx->workspaceId, 'created_by' => $ctx->userId, 'url' => $url, 'secret_enc' => $this->crypto->encrypt($secret, 'webhook'), 'events_json' => json_encode(array_values(array_unique($events)), JSON_THROW_ON_ERROR), 'created_at' => DbTime::format($this->clock->now())]);
        $this->audit->record('api.webhook_created', $ctx->userId, 'webhook', $id, [], $ctx->workspaceId);
        return ['id' => $id, 'secret' => $secret];
    }

    /** @return list<array<string,mixed>> */
    public function all(WorkspaceContext $ctx): array
    {
        $this->manage($ctx);
        return $this->db->select('SELECT public_id,url,events_json,enabled,consecutive_failures,created_at FROM webhook_subscriptions WHERE workspace_id=? ORDER BY id DESC', [$ctx->workspaceId]);
    }

    public function toggle(WorkspaceContext $ctx, string $id): void
    {
        $this->manage($ctx);
        if ($this->db->execute('UPDATE webhook_subscriptions SET enabled=1-enabled,consecutive_failures=0 WHERE workspace_id=? AND public_id=?', [$ctx->workspaceId, $id]) === 0) {
            throw new ApiException(404, 'not_found', 'Вебхук не найден.');
        }
        $this->audit->record('api.webhook_toggled', $ctx->userId, 'webhook', $id, [], $ctx->workspaceId);
    }

    /** @return list<array<string,mixed>> */
    public function deliveries(WorkspaceContext $ctx): array
    {
        $this->manage($ctx);
        return $this->db->select('SELECT d.public_id,d.status,d.attempts,d.next_attempt_at,d.completed_at,d.response_status,d.error_code,s.public_id subscription_id,e.type,e.public_id event_id FROM webhook_deliveries d JOIN webhook_subscriptions s ON s.id=d.subscription_id AND s.workspace_id=d.workspace_id JOIN outgoing_webhook_events e ON e.id=d.event_id AND e.workspace_id=d.workspace_id WHERE d.workspace_id=? ORDER BY d.id DESC LIMIT 50', [$ctx->workspaceId]);
    }

    public function retry(WorkspaceContext $ctx, string $id): void
    {
        $this->manage($ctx);
        $row = $this->db->select("SELECT d.id,s.enabled FROM webhook_deliveries d JOIN webhook_subscriptions s ON s.id=d.subscription_id AND s.workspace_id=d.workspace_id WHERE d.workspace_id=? AND d.public_id=? AND d.status='failed'", [$ctx->workspaceId, $id])[0] ?? throw new ApiException(404, 'not_found', 'Неуспешная доставка не найдена.');
        if (!(bool) $row['enabled']) {
            throw new ApiException(422, 'subscription_disabled', 'Сначала включите вебхук.');
        }
        $this->db->execute("UPDATE webhook_deliveries SET status='pending',attempts=0,completed_at=NULL,enqueued_at=NULL,next_attempt_at=?,lease_token=NULL,lease_until=NULL WHERE id=? AND status='failed'", [DbTime::format($this->clock->now()), $row['id']]);
        $this->audit->record('api.webhook_retry', $ctx->userId, 'webhook_delivery', $id, [], $ctx->workspaceId);
    }
}
