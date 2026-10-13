<?php

declare(strict_types=1);

namespace App\Tests\Feature\Source;

use App\Domain\Billing\Entitlements;
use App\Domain\Content\Providers\ProviderUsage;
use App\Integrations\ContentProviders\ProviderException;
use App\Integrations\ContentProviders\ProviderLimits;
use App\Tests\Support\SourceSelectionTestCase;
use App\Tests\Support\TestEnv;

/** Paid provider allowance is isolated, denied before dispatch and idempotent across safe retries. */
final class ProviderUsageTest extends SourceSelectionTestCase
{
    /** @var list<array<string,mixed>> */
    private array $originalPlans = [];
    protected function setUp(): void
    {
        parent::setUp();
        $this->originalPlans = $this->db->select('SELECT id, features_json, limits_json FROM plans');
    }
    protected function tearDown(): void
    {
        foreach ($this->originalPlans as $plan) {
            $this->db->execute('UPDATE plans SET features_json=?, limits_json=? WHERE id=?', [$plan['features_json'], $plan['limits_json'], $plan['id']]);
        }
        parent::tearDown();
    }
    private function allow(int $workspace): void
    {
        $plan = $this->app->container()->get(Entitlements::class)->plan($workspace);
        $this->db->execute('UPDATE plans SET features_json=?, limits_json=? WHERE id=?', [json_encode(array_merge($plan->features, ['content_text']), JSON_THROW_ON_ERROR), json_encode(array_replace($plan->limits, ['content_text_per_month' => 1]), JSON_THROW_ON_ERROR), $plan->id]);
    }
    public function testDeniedFeatureDoesNotInvokeProviderOrUseAllowanceAndFakeStillWorks(): void
    {
        [, $workspace] = $this->ownerWithWorkspace();
        $plan = $this->app->container()->get(Entitlements::class)->plan($workspace->id);
        $this->db->execute('UPDATE plans SET features_json=? WHERE id=?', [json_encode(array_values(array_diff($plan->features, ['content_text'])), JSON_THROW_ON_ERROR), $plan->id]);
        $usage = $this->app->container()->get(ProviderUsage::class);
        try {
            $usage->run($workspace->id, 'text', 'op', 'openai', static fn (): string => 'dispatch must not occur');
            self::fail('Plan must deny');
        } catch (ProviderException $e) {
            self::assertSame('plan_feature_denied', $e->category);
        }
        self::assertSame(0, $this->db->table('content_provider_usage')->count());
        self::assertSame('fake works', $usage->run($workspace->id, 'text', 'op', 'fake', static fn (): string => 'fake works'));
    }
    public function testWorkspaceBudgetsAndIdempotentRetry(): void
    {
        [, $a] = $this->ownerWithWorkspace('allow-a@example.com');
        [, $b] = $this->ownerWithWorkspace('allow-b@example.com');
        $this->allow($a->id);
        $c = $this->app->container();
        $usage = $c->get(ProviderUsage::class);
        $limits = new ProviderLimits(TestEnv::redis(), TestEnv::config(['CONTENT_PROVIDER_PER_MINUTE' => '2']), $this->clock, $usage);
        $dispatch = static function () use ($limits): string {
            $lease = $limits->acquire('POST', 'https://api.openai.com/v1/responses');
            $limits->release($lease, false);
            return 'dispatched';
        };
        self::assertSame('dispatched', $usage->run($a->id, 'text', 'op', 'openai', $dispatch));
        $usage->run($a->id, 'text', 'op', 'openai', $dispatch);
        $usage->run($b->id, 'text', 'op', 'openai', $dispatch);
        self::assertSame(2, $this->db->table('content_provider_usage')->count());
        foreach ([$a, $b] as $workspace) {
            $counter = $this->db->table('usage_counters')->where('workspace_id', '=', $workspace->id)->where('metric', '=', 'content_text')->first() ?? throw new \LogicException('Missing usage');
            self::assertSame(1, (int) $counter['value']);
        }
        $this->clock->advance(61);
        try {
            $usage->run($a->id, 'text', 'new-op', 'openai', $dispatch);
            self::fail('Allowance must deny');
        } catch (ProviderException $e) {
            self::assertSame('workspace_allowance_exhausted', $e->category);
        }
        self::assertSame(2, $this->db->table('content_provider_usage')->count());
    }
    public function testHttp429RetryUsesOneAllowance(): void
    {
        [, $workspace] = $this->ownerWithWorkspace();
        $this->allow($workspace->id);
        $usage = $this->app->container()->get(ProviderUsage::class);
        $limits = new ProviderLimits(TestEnv::redis(), TestEnv::config(), $this->clock, $usage);
        $http = (new \App\Tests\Support\MockHttpClient())
            ->expect('POST', 'https://api.openai.com/v1/responses', 429, '{"error":"limited"}')
            ->expect('POST', 'https://api.openai.com/v1/responses', 200, '{"ok":true}');
        $transport = new \App\Integrations\ContentProviders\ProviderHttp($http, static function (int $delay): void {
        }, $limits);
        $result = $usage->run($workspace->id, 'text', 'retry-op', 'openai', static fn (): array => $transport->json('POST', 'https://api.openai.com/v1/responses', []));
        self::assertTrue($result['ok']);
        self::assertCount(2, $http->requests);
        self::assertSame(1, $this->db->table('content_provider_usage')->count());
        $http->assertAllConsumed();
    }
    public function testUnknownHostAndRedisOutageNeverConsumeWorkspaceUsage(): void
    {
        [, $workspace] = $this->ownerWithWorkspace();
        $this->allow($workspace->id);
        $usage = $this->app->container()->get(ProviderUsage::class);
        foreach (['https://bad.example', 'https://api.openai.com/v1/responses'] as $url) {
            $limits = new ProviderLimits(new \Redis(), TestEnv::config(), $this->clock, $usage);
            try {
                $usage->run($workspace->id, 'text', 'op', 'openai', static fn (): array => $limits->acquire('POST', $url));
                self::fail('Must fail closed');
            } catch (ProviderException $e) {
                self::assertContains($e->category, ['invalid_provider_host', 'limits_unavailable']);
            }
        }
        self::assertSame(0, $this->db->table('content_provider_usage')->count());
    }
}
