<?php

declare(strict_types=1);

namespace App\Tests\Feature\Api;

use App\Domain\Api\ApiTokens;
use App\Domain\Workspace\Role;
use App\Kernel\HttpClient\SsrfGuard;
use App\Tests\Support\BillingTestCase;

/** Management UI permissions, one-time disclosure, CSRF and plan gates. */
final class ApiSettingsTest extends BillingTestCase
{
    public function testTokensShowSecretOnceAndCanBeRevokedWithoutDisclosure(): void
    {
        [$owner, $ws] = $this->ownerWithWorkspace();
        $this->payWithFake($ws, $owner);
        $this->actAs($owner);
        $base = '/w/' . $ws->publicId . '/settings/api';
        self::assertSame(200, $this->get($base)->status);
        self::assertSame(419, $this->request('POST', $base . '/tokens', ['name' => 'missing csrf'])->status);
        $created = $this->post($base . '/tokens', ['name' => 'n8n', 'scopes' => ['posts:read', 'posts:write'], 'expires_at' => $this->clock->now()->modify('+10 days')->format('Y-m-d')]);
        self::assertSame(200, $created->status);
        $token = self::capture('/(vkp_[a-f0-9]{64})/', $created->body);
        self::assertSame('no-store', $created->header('Cache-Control'));
        self::assertStringNotContainsString($token, $this->get($base)->body);
        $ctx = $this->contextFor($ws, $owner);
        $row = $this->app->container()->get(ApiTokens::class)->all($ctx)[0];
        self::assertSame(302, $this->post($base . '/tokens/' . $row['public_id'] . '/revoke')->status);
        self::assertNotNull($this->db->select('SELECT revoked_at FROM api_tokens')[0]['revoked_at']);
    }

    public function testFreePlanAndAuthorClientCannotManageTokensOrHooks(): void
    {
        [$owner, $ws] = $this->ownerWithWorkspace();
        $this->db->execute('DELETE FROM subscriptions WHERE workspace_id=?', [$ws->id]);
        $this->actAs($owner);
        $base = '/w/' . $ws->publicId . '/settings/api';
        $page = $this->get($base);
        self::assertSame(200, $page->status);
        self::assertStringContainsString('Нужен тариф Про', $page->body);
        self::assertSame(403, $this->post($base . '/tokens', ['name' => 'Denied', 'scopes' => ['posts:read']])->status);
        $this->payWithFake($ws, $owner);
        foreach ([Role::Author, Role::Client] as $role) {
            $member = $this->memberOf($ws, 'api-' . $role->value . '@example.com', $role, 'Участник');
            $this->actAs($member);
            self::assertSame(403, $this->get($base)->status);
            self::assertSame(403, $this->post($base . '/webhooks', ['url' => 'https://example.com'])->status);
        }
    }

    public function testWebhookSecretIsOneTimeAndValidationRetainsSafeFormInput(): void
    {
        [$owner, $ws] = $this->ownerWithWorkspace();
        $this->payWithFake($ws, $owner);
        $this->actAs($owner);
        $this->app->container()->instance(SsrfGuard::class, new SsrfGuard(static fn (string $host): array => ['93.184.216.34']));
        $base = '/w/' . $ws->publicId . '/settings/api';
        $created = $this->post($base . '/webhooks', ['url' => 'https://example.com/events', 'events' => ['approval.*']]);
        self::assertSame(200, $created->status);
        self::assertStringContainsString('Секрет подписи вебхука', $created->body);
        $secret = self::capture('/name="new-secret"[^>]+value="([a-f0-9]{64})"/', $created->body);
        self::assertStringNotContainsString($secret, $this->get($base)->body);
        self::assertStringNotContainsString($secret, json_encode($this->db->select('SELECT * FROM audit_log'), JSON_THROW_ON_ERROR));
        $bad = $this->post($base . '/webhooks', ['url' => 'http://127.0.0.1/', 'events' => ['post.published']]);
        self::assertSame(422, $bad->status);
        self::assertStringContainsString('http://127.0.0.1/', $bad->body);
    }

    public function testOperationalCountersAndBusinessMetricsContainNoCredentials(): void
    {
        [$owner, $ws] = $this->ownerWithWorkspace();
        $this->payWithFake($ws, $owner);
        $ctx = $this->contextFor($ws, $owner);
        $token = $this->app->container()->get(ApiTokens::class)->create($ctx, 'Metrics token', ['posts:read']);
        $counters = $this->app->container()->get(\App\Domain\Api\ApiOperations::class)->forWorkspace($ws->id);
        self::assertSame(['tokens' => 1, 'hooks' => 0, 'pending' => 0, 'failed' => 0, 'last' => null], $counters);
        self::assertStringNotContainsString($token['secret'], json_encode($this->db->select('SELECT * FROM analytics_events'), JSON_THROW_ON_ERROR));
        $this->app->container()->get(\App\Domain\Analytics\MetricsAggregator::class)->day($this->clock->now());
        $metric = $this->db->select("SELECT * FROM metrics_daily WHERE metric='api_created' AND dim='api_token_created'");
        self::assertCount(1, $metric);
    }

    public function testSelfHostedDocsAndSpecContainNoRuntimeCdn(): void
    {
        $docs = $this->get('/api/docs');
        self::assertSame(200, $docs->status, $docs->body);
        self::assertStringContainsString('/api/openapi.yaml', $docs->body);
        self::assertStringContainsString('api-docs.js', $docs->body);
        self::assertStringNotContainsString('cdn.', $docs->body);
        self::assertStringNotContainsString('unsafe-eval', $docs->header('Content-Security-Policy') ?? '');
    }
}
