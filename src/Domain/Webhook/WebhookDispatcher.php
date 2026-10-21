<?php

declare(strict_types=1);

namespace App\Domain\Webhook;

use App\Domain\Billing\Entitlements;
use App\Domain\Workspace\WorkspaceRepository;
use App\Domain\User\UserRepository;
use App\Kernel\{Config};
use App\Kernel\Database\Connection;
use App\Kernel\HttpClient\{HttpClientInterface, SsrfGuard};
use App\Kernel\Queue\Queue;
use App\Kernel\Security\Crypto;
use App\Support\{Clock, DbTime};
use Symfony\Component\Uid\Ulid;

/** Outbox fan-out, fenced HTTP delivery and lost-worker recovery using the existing Queue/Scheduler. */
final class WebhookDispatcher
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly Queue $queue, private readonly HttpClientInterface $http, private readonly SsrfGuard $guard, private readonly Crypto $crypto, private readonly Webhooks $subscriptions, private readonly WorkspaceRepository $workspaces, private readonly UserRepository $users, private readonly Entitlements $plans, private readonly Config $config)
    {
    }

    public function tick(): void
    {
        $this->db->transaction(function (): void {
            foreach ($this->db->select('SELECT * FROM outgoing_webhook_events WHERE dispatched_at IS NULL ORDER BY id LIMIT 100 FOR UPDATE SKIP LOCKED') as $event) {
                foreach ($this->db->select('SELECT * FROM webhook_subscriptions WHERE workspace_id=? AND enabled=1 AND created_at<=?', [$event['workspace_id'], $event['created_at']]) as $sub) {
                    $types = json_decode((string) $sub['events_json'], true, 16, JSON_THROW_ON_ERROR);
                    if (!in_array($event['type'], $types, true) && !(str_starts_with((string) $event['type'], 'approval.') && in_array('approval.*', $types, true))) {
                        continue;
                    }
                    $this->db->execute("INSERT IGNORE INTO webhook_deliveries(public_id,workspace_id,subscription_id,event_id,next_attempt_at) VALUES(?,?,?,?,?)", [(string) new Ulid(), $event['workspace_id'], $sub['id'], $event['id'], DbTime::format($this->clock->now())]);
                }
                $this->db->execute('UPDATE outgoing_webhook_events SET dispatched_at=? WHERE id=?', [DbTime::format($this->clock->now()), $event['id']]);
            }
            $now = DbTime::format($this->clock->now());
            foreach ($this->db->select("SELECT d.id FROM webhook_deliveries d WHERE d.status IN ('pending','processing') AND d.next_attempt_at<=? AND (d.lease_until IS NULL OR d.lease_until<?)
                AND NOT EXISTS (SELECT 1 FROM jobs j WHERE JSON_EXTRACT(j.payload_json,'$.data.delivery_id')=d.id AND JSON_UNQUOTE(JSON_EXTRACT(j.payload_json,'$.class'))=?) ORDER BY d.next_attempt_at,d.id LIMIT 100 FOR UPDATE SKIP LOCKED", [$now, $now, DeliverWebhookJob::class]) as $row) {
                $this->queue->dispatch(new DeliverWebhookJob((int) $row['id']));
                $this->db->execute('UPDATE webhook_deliveries SET enqueued_at=? WHERE id=?', [$now, $row['id']]);
            }
        });
    }

    public function deliver(int $id): void
    {
        $now = $this->clock->now();
        $lease = bin2hex(random_bytes(16));
        $owned = $this->db->execute("UPDATE webhook_deliveries SET status='processing',attempts=attempts+1,lease_token=?,lease_until=? WHERE id=? AND status IN ('pending','processing') AND next_attempt_at<=? AND (lease_until IS NULL OR lease_until<?)", [$lease, DbTime::format($now->modify('+2 minutes')), $id, DbTime::format($now), DbTime::format($now)]);
        if ($owned === 0) {
            return;
        }
        $row = $this->db->select('SELECT d.*,s.enabled,s.url,s.secret_enc,s.created_by,e.payload_json FROM webhook_deliveries d JOIN webhook_subscriptions s ON s.id=d.subscription_id AND s.workspace_id=d.workspace_id JOIN outgoing_webhook_events e ON e.id=d.event_id AND e.workspace_id=d.workspace_id WHERE d.id=?', [$id])[0];
        $status = null;
        $error = null;
        $skipped = false;
        try {
            $ws = $this->workspaces->findById((int) $row['workspace_id']);
            $member = $this->workspaces->membership((int) $row['workspace_id'], (int) $row['created_by']);
            $user = $this->users->find((int) $row['created_by']);
            if (!(bool) $row['enabled'] || $ws === null || $member === null || $user === null || $user->isBlocked() || !$this->plans->hasFeature($ws->id, 'api')) {
                $skipped = true;
            } else {
                $this->subscriptions->manage(\App\Domain\Workspace\WorkspaceContext::from($ws, $member));
                // Revalidate every delivery, and GuzzleHttpClient pins the actual connection again (no redirects on POST).
                $this->guard->assertSafe((string) $row['url']);
                $payload = (string) $row['payload_json'];
                $event = json_decode($payload, true, 16, JSON_THROW_ON_ERROR);
                $timestamp = (string) $now->getTimestamp();
                $signature = hash_hmac('sha256', $timestamp . '.' . $payload, $this->crypto->decrypt((string) $row['secret_enc'], 'webhook'));
                $response = $this->http->request('POST', (string) $row['url'], ['user_url' => true, 'timeout' => 10, 'connect_timeout' => 3, 'allow_redirects' => false, 'follow_redirects' => false, 'http_errors' => false, 'stream' => true, 'headers' => ['Content-Type' => 'application/json', 'X-Signature' => 't=' . $timestamp . ',v1=' . $signature, 'X-Webhook-Id' => $event['id']], 'body' => $payload]);
                $status = $response->getStatusCode();
                $response->getBody()->close();
                if ($status < 200 || $status >= 300) {
                    $error = 'http_error';
                }
            }
        } catch (\App\Domain\Api\ApiException|\App\Domain\Billing\PlanLimitException) {
            $skipped = true;
        } catch (\App\Kernel\Exception\SsrfException) {
            $error = 'unsafe_url';
        } catch (\Throwable) {
            $error = 'delivery_unavailable';
        }
        $retry = false;
        $this->db->transaction(function () use ($row, $id, $lease, $status, $error, $skipped, &$retry): void {
            if ($this->db->select("SELECT id FROM webhook_deliveries WHERE id=? AND lease_token=? AND status='processing' FOR UPDATE", [$id, $lease]) === []) {
                return;
            }
            $this->db->table('webhook_delivery_attempts')->insert(['delivery_id' => $id, 'attempted_at' => DbTime::format($this->clock->now()), 'response_status' => $status, 'error_code' => $skipped ? 'subscription_unavailable' : $error]);
            $finished = $skipped || $error === null || (int) $row['attempts'] >= 5;
            $state = $skipped ? 'skipped' : ($error === null ? 'completed' : ($finished ? 'failed' : 'pending'));
            $retry = !$finished;
            $next = $this->clock->now()->modify('+' . (new DeliverWebhookJob($id))->backoff((int) $row['attempts']) . ' seconds');
            $this->db->execute('UPDATE webhook_deliveries SET status=?,response_status=?,error_code=?,next_attempt_at=?,completed_at=?,lease_until=NULL WHERE id=? AND lease_token=?', [$state, $status, $skipped ? 'subscription_unavailable' : $error, DbTime::format($next), $finished ? DbTime::format($this->clock->now()) : null, $id, $lease]);
            if (!$skipped) {
                if ($error === null) {
                    $this->db->execute('UPDATE webhook_subscriptions SET consecutive_failures=0 WHERE id=?', [$row['subscription_id']]);
                } else {
                    $this->db->execute('UPDATE webhook_subscriptions SET consecutive_failures=consecutive_failures+1 WHERE id=?', [$row['subscription_id']]);
                    $this->db->execute('UPDATE webhook_subscriptions SET enabled=0 WHERE id=? AND consecutive_failures>=?', [$row['subscription_id'], $this->config->int('api.webhook_failures', 10)]);
                }
            }
        });
        if ($retry) {
            throw new \RuntimeException('Webhook temporarily unavailable.');
        }
    }

    public function prune(): void
    {
        $this->db->execute('DELETE FROM api_idempotency WHERE expires_at<?', [DbTime::format($this->clock->now())]);
        $this->db->execute("DELETE e FROM outgoing_webhook_events e WHERE e.dispatched_at IS NOT NULL AND e.created_at<? AND NOT EXISTS (SELECT 1 FROM webhook_deliveries d WHERE d.event_id=e.id AND d.status IN ('pending','processing'))", [DbTime::format($this->clock->now()->modify('-' . $this->config->int('api.retention_days', 35) . ' days'))]);
    }
}
