<?php

declare(strict_types=1);

namespace App\Tests\Feature\Api;

use App\Domain\Api\ApiException;
use App\Domain\Webhook\{Webhooks, WebhookOutbox, WebhookDispatcher};
use App\Domain\Workspace\WorkspaceContext;
use App\Kernel\HttpClient\SsrfGuard;
use App\Kernel\Queue\{Queue, Worker};
use App\Domain\Audit\AuditLog;
use App\Support\DbTime;
use App\Tests\Support\BillingTestCase;

/** Durable webhook contracts, DNS rebinding, signatures, backoff and crash/ACK-loss safety. */
final class WebhookTest extends BillingTestCase
{
    /** @var list<string> */
    private array $addresses = ['93.184.216.34'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->container()->instance(SsrfGuard::class, new SsrfGuard(fn (string $host): array => str_ends_with($host, '.example') ? $this->addresses : []));
    }

    private function hooks(): Webhooks
    {
        return $this->app->container()->get(Webhooks::class);
    }

    private function dispatcher(): WebhookDispatcher
    {
        return $this->app->container()->get(WebhookDispatcher::class);
    }

    /** @return array{WorkspaceContext,array{id:string,secret:string}} */
    private function fixture(): array
    {
        [$owner, $ws] = $this->ownerWithWorkspace();
        $this->payWithFake($ws, $owner);
        $ctx = $this->contextFor($ws, $owner);
        return [$ctx, $this->hooks()->create($ctx, 'https://first.example/hook', ['post.published', 'post.failed', 'channel.error', 'approval.*'])];
    }

    private function event(WorkspaceContext $ctx, string $key = 'fixture:1', string $type = 'post.published'): int
    {
        $this->app->container()->get(WebhookOutbox::class)->capture($ctx->workspaceId, $type, ['post_id' => (string) new \Symfony\Component\Uid\Ulid()], $key);
        $this->dispatcher()->tick();
        return (int) $this->db->select('SELECT id FROM webhook_deliveries ORDER BY id DESC LIMIT 1')[0]['id'];
    }

    private function failAttempt(int $id): void
    {
        try {
            $this->dispatcher()->deliver($id);
        } catch (\RuntimeException $e) {
            self::assertSame('Webhook temporarily unavailable.', $e->getMessage());
        }
    }

    public function testPrivateUnresolvableUrlsAndForeignSubscriptionAreRejected(): void
    {
        [$ctx] = $this->fixture();
        foreach (['http://127.0.0.1/hook', 'http://169.254.169.254/', 'https://unknown.invalid/hook', 'https://user:password@first.example/'] as $url) {
            try {
                $this->hooks()->create($ctx, $url, ['post.published']);
                self::fail('Unsafe URL accepted');
            } catch (ApiException $e) {
                self::assertSame(422, $e->status);
            }
        }
        [$other, $ws] = $this->ownerWithWorkspace('foreign-hook@example.com');
        $this->payWithFake($ws, $other);
        try {
            $this->hooks()->toggle($this->contextFor($ws, $other), $this->hooks()->all($ctx)[0]['public_id']);
            self::fail('Foreign subscription changed');
        } catch (ApiException $e) {
            self::assertSame(404, $e->status);
        }
    }

    public function testSignatureEnvelopeEncryptionAndDeduplicatedFanout(): void
    {
        [$ctx, $created] = $this->fixture();
        $row = $this->db->select('SELECT * FROM webhook_subscriptions')[0];
        self::assertNotSame($created['secret'], $row['secret_enc']);
        self::assertStringNotContainsString($created['secret'], json_encode($this->hooks()->all($ctx), JSON_THROW_ON_ERROR));
        $id = $this->event($ctx);
        $this->dispatcher()->tick();
        self::assertSame(1, $this->db->table('webhook_deliveries')->count());
        $jobs = $this->db->select("SELECT id FROM jobs WHERE JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.class'))=?", [\App\Domain\Webhook\DeliverWebhookJob::class]);
        self::assertCount(1, $jobs);
        $this->http->expect('POST', 'https://first.example/hook', 204);
        $this->dispatcher()->deliver($id);
        $call = $this->http->requests[array_key_last($this->http->requests) ?? throw new \LogicException('Missing HTTP request')];
        $timestamp = (string) $this->clock->now()->getTimestamp();
        self::assertSame('t=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $call['options']['body'], $created['secret']), $call['options']['headers']['X-Signature']);
        self::assertTrue($call['options']['user_url']);
        self::assertFalse($call['options']['follow_redirects']);
        $payload = json_decode($call['options']['body'], true, 16, JSON_THROW_ON_ERROR);
        self::assertSame($ctx->workspacePublicId, $payload['workspace_id']);
        self::assertSame(1, $payload['version']);
        self::assertSame($payload['id'], $call['options']['headers']['X-Webhook-Id']);
        self::assertSame('completed', $this->db->select('SELECT status FROM webhook_deliveries')[0]['status']);
        $count = count($this->http->requests);
        $this->dispatcher()->deliver($id);
        self::assertSame($count, count($this->http->requests));
    }

    public function testRebindingIsCheckedAgainBeforeEveryDeliveryAndRetry(): void
    {
        [$ctx] = $this->fixture();
        $id = $this->event($ctx);
        $before = count($this->http->requests);
        $this->addresses = ['127.0.0.1'];
        $this->failAttempt($id);
        self::assertSame($before, count($this->http->requests));
        self::assertSame('unsafe_url', $this->db->select('SELECT error_code FROM webhook_deliveries')[0]['error_code']);
        $this->addresses = ['93.184.216.34'];
        $this->clock->advance(61);
        $this->http->expect('POST', 'https://first.example/hook', 200);
        $this->dispatcher()->deliver($id);
        self::assertSame('completed', $this->db->select('SELECT status FROM webhook_deliveries')[0]['status']);
    }

    public function testRetryBackoffAndSafeResponseLogging(): void
    {
        [$ctx, $created] = $this->fixture();
        $id = $this->event($ctx);
        $this->http->expect('POST', 'https://first.example/hook', 429, 'secret echo ' . $created['secret']);
        $this->failAttempt($id);
        $row = $this->db->select('SELECT * FROM webhook_deliveries')[0];
        self::assertSame('pending', $row['status']);
        self::assertSame(DbTime::format($this->clock->now()->modify('+60 seconds')), $row['next_attempt_at']);
        $calls = count($this->http->requests);
        $this->dispatcher()->deliver($id);
        self::assertSame($calls, count($this->http->requests));
        $this->clock->advance(61);
        $this->http->expect('POST', 'https://first.example/hook', 500);
        $this->failAttempt($id);
        self::assertSame(DbTime::format($this->clock->now()->modify('+300 seconds')), $this->db->select('SELECT next_attempt_at FROM webhook_deliveries')[0]['next_attempt_at']);
        self::assertStringNotContainsString($created['secret'], json_encode($this->db->select('SELECT * FROM webhook_delivery_attempts'), JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString($created['secret'], json_encode($row, JSON_THROW_ON_ERROR));
    }

    public function testFailedSubscriptionDoesNotBlockOthersAndAutoDisables(): void
    {
        [$ctx] = $this->fixture();
        $this->hooks()->create($ctx, 'https://second.example/hook', ['post.published']);
        for ($event = 0; $event < 2; ++$event) {
            $this->event($ctx, 'failure:' . $event);
            $bad = (int) $this->db->select('SELECT d.id FROM webhook_deliveries d JOIN webhook_subscriptions s ON s.id=d.subscription_id WHERE s.url=? ORDER BY d.id DESC LIMIT 1', ['https://first.example/hook'])[0]['id'];
            $good = (int) $this->db->select('SELECT d.id FROM webhook_deliveries d JOIN webhook_subscriptions s ON s.id=d.subscription_id WHERE s.url=? ORDER BY d.id DESC LIMIT 1', ['https://second.example/hook'])[0]['id'];
            for ($attempt = 0; $attempt < 5; ++$attempt) {
                $this->http->expect('POST', 'https://first.example/hook', 500);
                $this->failAttempt($bad);
                $this->clock->advance(3601);
            }
            $this->http->expect('POST', 'https://second.example/hook', 200);
            $this->dispatcher()->deliver($good);
        }
        $sub = $this->db->select('SELECT enabled,consecutive_failures FROM webhook_subscriptions WHERE url=?', ['https://first.example/hook'])[0];
        self::assertSame(0, (int) $sub['enabled']);
        self::assertSame(10, (int) $sub['consecutive_failures']);
        self::assertSame(2, $this->db->table('webhook_deliveries')->where('status', '=', 'completed')->count());
    }

    public function testManualRetryKeepsEventIdentityAndAuditHistory(): void
    {
        [$ctx] = $this->fixture();
        $id = $this->event($ctx);
        for ($i = 0; $i < 5; ++$i) {
            $this->http->expect('POST', 'https://first.example/hook', 500);
            $this->failAttempt($id);
            $this->clock->advance(3601);
        }
        $row = $this->db->select('SELECT * FROM webhook_deliveries')[0];
        self::assertSame('failed', $row['status']);
        $this->hooks()->retry($ctx, $row['public_id']);
        $this->http->expect('POST', 'https://first.example/hook', 200);
        $this->dispatcher()->deliver($id);
        $current = $this->db->select('SELECT * FROM webhook_deliveries')[0];
        self::assertSame($row['event_id'], $current['event_id']);
        self::assertSame('completed', $current['status']);
        self::assertSame(6, $this->db->table('webhook_delivery_attempts')->count());
    }

    public function testWorkerRestartAfterResponseBeforeAckDoesNotSendTwice(): void
    {
        [$ctx] = $this->fixture();
        $this->db->execute('DELETE FROM jobs');
        $id = $this->event($ctx);
        $queue = $this->app->container()->get(Queue::class);
        self::assertNotNull($queue->reserve('default', 'crashed-worker'));
        $this->http->expect('POST', 'https://first.example/hook', 200);
        $this->dispatcher()->deliver($id);
        $calls = count($this->http->requests);
        $this->clock->advance(1000);
        self::assertTrue($this->app->container()->get(Worker::class)->runNext('default', 'replacement'));
        self::assertSame($calls, count($this->http->requests));
        self::assertSame(1, $this->db->table('webhook_delivery_attempts')->count());
    }

    public function testLostWorkerLeaseIsRecoveredAndDisabledSubscriptionCannotDispatch(): void
    {
        [$ctx, $created] = $this->fixture();
        $this->db->execute('DELETE FROM jobs');
        $id = $this->event($ctx);
        $this->db->execute("UPDATE webhook_deliveries SET status='processing',lease_token=?,lease_until=? WHERE id=?", [str_repeat('a', 32), DbTime::format($this->clock->now()->modify('+2 minutes')), $id]);
        $this->app->container()->get(Queue::class)->reserve('default', 'dead');
        $this->clock->advance(1000);
        $this->http->expect('POST', 'https://first.example/hook', 200);
        $this->app->container()->get(Worker::class)->runNext('default', 'restarted');
        self::assertSame('completed', $this->db->select('SELECT status FROM webhook_deliveries')[0]['status']);
        $second = $this->event($ctx, 'disabled');
        $this->hooks()->toggle($ctx, $created['id']);
        $calls = count($this->http->requests);
        $this->dispatcher()->deliver($second);
        self::assertSame($calls, count($this->http->requests));
        self::assertSame('skipped', $this->db->select('SELECT status FROM webhook_deliveries WHERE id=?', [$second])[0]['status']);
    }

    public function testExistingPublishingApprovalAndChannelChangesCaptureOutbox(): void
    {
        [$ctx] = $this->fixture();
        $ws = $this->workspaces->findById($ctx->workspaceId) ?? throw new \LogicException();
        $owner = $this->app->container()->get(\App\Domain\User\UserRepository::class)->find($ctx->userId) ?? throw new \LogicException();
        $channel = $this->fakeChannel($ws, $owner);
        [$post] = $this->scheduled($ctx, [$channel], '+1 minute', 'Real domain publication');
        $this->clock->advance(61);
        $this->drain();
        self::assertSame(1, $this->db->table('outgoing_webhook_events')->where('type', '=', 'post.published')->count());
        $this->app->container()->get(AuditLog::class)->record('approval.approved', $ctx->userId, 'post', $post->publicId, ['private' => 'Never deliver this'], $ctx->workspaceId);
        $this->app->container()->get(\App\Domain\Channel\ChannelSystem::class)->recordBroken($channel, \App\Domain\Channel\ChannelStatus::Error, 'Never deliver this');
        self::assertSame(1, $this->db->table('outgoing_webhook_events')->where('type', '=', 'approval.approved')->count());
        self::assertSame(1, $this->db->table('outgoing_webhook_events')->where('type', '=', 'channel.error')->count());
        self::assertStringNotContainsString('Never deliver this', json_encode($this->db->select('SELECT payload_json FROM outgoing_webhook_events'), JSON_THROW_ON_ERROR));
    }

    public function testRetentionKeepsPendingOutboxAndPrunesOnlyTerminalHistory(): void
    {
        [$ctx] = $this->fixture();
        $id = $this->event($ctx);
        $this->http->expect('POST', 'https://first.example/hook', 200);
        $this->dispatcher()->deliver($id);
        $this->event($ctx, 'still-pending');
        $this->db->execute('UPDATE outgoing_webhook_events SET created_at=?', [DbTime::format($this->clock->now()->modify('-36 days'))]);
        $this->dispatcher()->prune();
        self::assertSame(1, $this->db->table('outgoing_webhook_events')->count());
        self::assertSame(1, $this->db->table('webhook_deliveries')->count());
    }
}
