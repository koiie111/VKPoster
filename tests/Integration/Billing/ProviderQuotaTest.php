<?php

declare(strict_types=1);

namespace App\Tests\Integration\Billing;

use App\Domain\Billing\Entitlements;
use App\Domain\Content\Operations\ProviderQuota;
use App\Integrations\ContentProviders\ProviderException;
use App\Tests\Support\BillingTestCase;
use App\Tests\Support\TestEnv;
use PHPUnit\Framework\Attributes\CoversClass;

/** Paid provider calls are charged to the workspace's own plan allowance, never to the shared service budget. */
#[CoversClass(ProviderQuota::class)]
final class ProviderQuotaTest extends BillingTestCase
{
    /** @param array<string, string> $env */
    private function quota(array $env = []): ProviderQuota
    {
        return new ProviderQuota(
            $this->db,
            $this->app->container()->get(Entitlements::class),
            TestEnv::config($env + ['CONTENT_TEXT_PROVIDER' => 'openai', 'CONTENT_VIDEO_PROVIDER' => 'replicate', 'CONTENT_WORKSPACE_PER_MINUTE' => '50']),
            $this->clock,
            TestEnv::redis(),
        );
    }

    public function testCallsStopWhenThePlanCreditsAreUsedUp(): void
    {
        [, $workspace] = $this->ownerWithWorkspace();
        $this->givePlan($workspace, 'free', ['ai_credits' => 2]);
        $quota = $this->quota();

        self::assertSame('ok', $quota->run($workspace->id, 'text', static fn (): string => 'ok'));
        $quota->run($workspace->id, 'text', static fn (): string => 'ok');
        $called = false;
        try {
            $quota->run($workspace->id, 'text', static function () use (&$called): string {
                $called = true;

                return 'paid call';
            });
            self::fail('The third call must be refused');
        } catch (ProviderException $e) {
            self::assertSame('quota_exceeded', $e->category);
            self::assertFalse($e->retryable);
        }
        self::assertFalse($called, 'a refused call never reaches the provider');
        self::assertSame(['used' => 2, 'limit' => 2], $quota->usage($workspace->id));
    }

    public function testAnExpensiveCallIsRefusedWhenItDoesNotFitTheRemainder(): void
    {
        [, $workspace] = $this->ownerWithWorkspace();
        $this->givePlan($workspace, 'free', ['ai_credits' => 10]);
        $quota = $this->quota();

        $quota->run($workspace->id, 'text', static fn (): int => 1);
        $this->expectException(ProviderException::class);
        $quota->run($workspace->id, 'video', static fn (): int => 1);
    }

    public function testUnlimitedPlansAreStillCounted(): void
    {
        [, $workspace] = $this->ownerWithWorkspace();
        $this->givePlan($workspace, 'pro', ['ai_credits' => null]);
        $quota = $this->quota();

        $quota->run($workspace->id, 'video', static fn (): int => 1);

        self::assertSame(['used' => 10, 'limit' => null], $quota->usage($workspace->id));
    }

    public function testAWorkspaceDoesNotSpendAnotherWorkspacesCredits(): void
    {
        [, $first] = $this->ownerWithWorkspace('one@example.com');
        [, $second] = $this->ownerWithWorkspace('two@example.com');
        $this->givePlan($first, 'free', ['ai_credits' => 1]);
        $this->givePlan($second, 'free', ['ai_credits' => 1]);
        $quota = $this->quota();

        $quota->run($first->id, 'text', static fn (): int => 1);

        self::assertSame(1, $quota->run($second->id, 'text', static fn (): int => 1));
        self::assertSame(1, $quota->usage($second->id)['used']);
    }

    public function testACreditComesBackWhenTheProviderRefusedBeforeDoingWork(): void
    {
        [, $workspace] = $this->ownerWithWorkspace();
        $this->givePlan($workspace, 'free', ['ai_credits' => 1]);
        $quota = $this->quota();

        try {
            $quota->run($workspace->id, 'text', static function (): never {
                throw new ProviderException('rate_limited', true);
            });
        } catch (ProviderException) {
        }
        self::assertSame(0, $quota->usage($workspace->id)['used']);

        try {
            $quota->run($workspace->id, 'text', static function (): never {
                throw new ProviderException('transport_failed', false);
            });
        } catch (ProviderException) {
        }
        self::assertSame(1, $quota->usage($workspace->id)['used'], 'an ambiguous failure may have been billed by the provider');
    }

    public function testTheAllowanceStartsAgainNextMonth(): void
    {
        [, $workspace] = $this->ownerWithWorkspace();
        $this->givePlan($workspace, 'free', ['ai_credits' => 1]);
        $quota = $this->quota();
        $quota->run($workspace->id, 'text', static fn (): int => 1);

        $this->clock->advance(40 * 86400);

        self::assertSame(1, $quota->run($workspace->id, 'text', static fn (): int => 1));
    }

    public function testOneWorkspaceCannotFloodTheProvider(): void
    {
        [, $workspace] = $this->ownerWithWorkspace();
        $this->givePlan($workspace, 'pro', ['ai_credits' => null]);
        $quota = $this->quota(['CONTENT_WORKSPACE_PER_MINUTE' => '2']);

        $quota->run($workspace->id, 'text', static fn (): int => 1);
        $quota->run($workspace->id, 'text', static fn (): int => 1);
        try {
            $quota->run($workspace->id, 'text', static fn (): int => 1);
            self::fail('The third call within a minute must wait');
        } catch (ProviderException $e) {
            self::assertSame('workspace_rate_limited', $e->category);
            self::assertTrue($e->retryable);
        }
        $this->clock->advance(61);
        self::assertSame(1, $quota->run($workspace->id, 'text', static fn (): int => 1));
    }

    public function testFakeProvidersAreFree(): void
    {
        [, $workspace] = $this->ownerWithWorkspace();
        $this->givePlan($workspace, 'free', ['ai_credits' => 0]);
        $quota = $this->quota(['CONTENT_TEXT_PROVIDER' => 'fake']);

        self::assertSame(1, $quota->run($workspace->id, 'text', static fn (): int => 1));
        self::assertSame(0, $quota->usage($workspace->id)['used']);
    }
}
