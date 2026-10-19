<?php

declare(strict_types=1);

namespace App\Domain\Webhook;

use App\Kernel\Database\Connection;
use App\Support\{Clock, DbTime};
use Symfony\Component\Uid\Ulid;

/** Durable event capture without HTTP or Queue dependencies, safe to use inside a domain transaction. */
final class WebhookOutbox
{
    public const EVENTS = ['post.published', 'post.failed', 'channel.error', 'approval.submitted', 'approval.approved', 'approval.changes_requested', 'approval.revision_changed', 'approval.scheduled', 'approval.settings_updated'];

    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    /** @param array<string,string> $data Only public identifiers and fixed enum values. */
    public function capture(int $workspace, string $type, array $data, string $key): void
    {
        if (!in_array($type, self::EVENTS, true)) {
            return;
        }
        if ($this->db->select('SELECT id FROM webhook_subscriptions WHERE workspace_id=? AND enabled=1 LIMIT 1', [$workspace]) === []) {
            return;
        }
        $ws = $this->db->select('SELECT public_id FROM workspaces WHERE id=?', [$workspace])[0] ?? null;
        if ($ws === null) {
            return;
        }
        $id = (string) new Ulid();
        $now = $this->clock->now();
        $payload = ['id' => $id, 'type' => $type, 'created_at' => $now->format(DATE_ATOM), 'workspace_id' => $ws['public_id'], 'version' => 1, 'data' => $data];
        $this->db->execute('INSERT IGNORE INTO outgoing_webhook_events(public_id,workspace_id,event_key,type,payload_json,created_at) VALUES(?,?,?,?,?,?)', [$id, $workspace, $key, $type, json_encode($payload, JSON_THROW_ON_ERROR), DbTime::format($now)]);
    }
}
