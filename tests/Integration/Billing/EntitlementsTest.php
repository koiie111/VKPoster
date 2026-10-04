<?php

declare(strict_types=1);

namespace App\Tests\Integration\Billing;

use App\Domain\Billing\Entitlements;
use App\Domain\Billing\PlanLimitException;
use App\Domain\Channel\ChannelRepository;
use App\Domain\Channel\ChannelStatus;
use App\Domain\Workspace\Role;
use App\Domain\Workspace\WorkspaceService;
use App\Tests\Support\BillingTestCase;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * "Does the plan allow it?" against a real database: the counting rules (what occupies a channel place, which month a post belongs to,
 * who counts as a member) and the messages people see.
 */
#[CoversClass(Entitlements::class)]
final class EntitlementsTest extends BillingTestCase
{
    private function entitlements(): Entitlements
    {
        return $this->app->container()->get(Entitlements::class);
    }

    public function testAWorkspaceWithoutASubscriptionIsOnFree(): void
    {
        [, $workspace] = $this->ownerWithWorkspace();
        $this->db->execute('DELETE FROM subscriptions WHERE workspace_id = ?', [$workspace->id]);

        $plan = $this->entitlements()->plan($workspace->id);

        self::assertSame('free', $plan->code);
        self::assertSame(2, $this->entitlements()->limit($workspace->id, 'channels'));
    }

    public function testANewPersonalWorkspaceStartsWithTheProTrial(): void
    {
        [, $workspace] = $this->ownerWithWorkspace();

        $entitlement = $this->entitlements()->for($workspace->id);

        self::assertSame('pro', $entitlement->plan->code);
        self::assertTrue($entitlement->subscription?->isTrial());
        self::assertTrue($entitlement->hasFeature('approvals'));
        self::assertSame(30, $entitlement->limit('channels'));
        self::assertNull($entitlement->limit('posts_per_month'), 'unlimited is null');
    }

    public function testChannelsPausedByThePersonDoNotUseUpTheAllowance(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->givePlan($workspace, 'free');
        $first = $this->makeChannel($workspace, $owner, '-1001', 'Первый');
        $this->makeChannel($workspace, $owner, '-1002', 'Второй');
        $e = $this->entitlements();

        self::assertSame(0, $e->channelsLeft($workspace->id));
        self::assertFalse($e->canAddChannel($workspace->id));

        $this->app->container()->get(ChannelRepository::class)->setStatus($this->contextFor($workspace, $owner), $first, ChannelStatus::Paused);

        self::assertSame(1, $e->channelsLeft($workspace->id));
        self::assertTrue($e->canAddChannel($workspace->id));
    }

    public function testTheChannelLimitMessageNamesThePlanAndTheNumber(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->givePlan($workspace, 'free');
        $this->makeChannel($workspace, $owner, '-1001');
        $this->makeChannel($workspace, $owner, '-1002');

        try {
            $this->entitlements()->assertCanAddChannel($workspace->id);
            self::fail('the third channel on Free must be refused');
        } catch (PlanLimitException $e) {
            self::assertSame('channels', $e->limit);
            self::assertStringContainsString('«Free»', $e->getMessage());
            self::assertStringContainsString('до 2 каналов', $e->getMessage());
        }
    }

    public function testTheGlobalCeilingAppliesEvenToUnlimitedPlans(): void
    {
        $this->app = \App\Tests\Support\TestEnv::app(['CHANNELS_MAX' => '1']);
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->givePlan($workspace, 'agency');
        $this->makeChannel($workspace, $owner, '-1001');

        $this->expectException(PlanLimitException::class);
        $this->expectExceptionMessage('лимит каналов');
        $this->app->container()->get(Entitlements::class)->assertCanAddChannel($workspace->id);
    }

    public function testPostsAreCountedInTheMonthTheyAreCalledFor(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $channel = $this->fakeChannel($workspace, $owner);
        $context = $this->contextFor($workspace, $owner);
        $this->service()->saveDraft($context, null, $this->draft('черновик', [$channel]));
        [$planned] = $this->scheduled($context, [$channel], '+2 days');
        [$cancelled] = $this->scheduled($context, [$channel], '+3 days');
        $this->service()->cancel($context, $cancelled);
        $e = $this->entitlements();

        $month = $planned->scheduledAt ?? self::fail('planned post has a time');

        self::assertSame(1, $e->postsInMonth($workspace->id, $month), 'a draft and a cancelled post do not count');
        self::assertSame(0, $e->postsInMonth($workspace->id, $month->modify('+2 months')));
    }

    public function testTheMonthIsCountedInTheWorkspaceTimeZone(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        // Moscow is UTC+3: 21:30 UTC on 31 October is already 1 November there.
        $this->db->execute('UPDATE workspaces SET timezone = ? WHERE id = ?', ['Europe/Moscow', $workspace->id]);
        $this->db->execute(
            'INSERT INTO posts (public_id, workspace_id, status, base_text, scheduled_at, timezone, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))',
            ['01JABCDEFGHJKMNPQRSTVWXYZ1', $workspace->id, 'scheduled', 'x', '2026-10-31 21:30:00', 'Europe/Moscow'],
        );
        $e = $this->entitlements();

        self::assertSame(0, $e->postsInMonth($workspace->id, new DateTimeImmutable('2026-10-15 12:00:00', new DateTimeZone('UTC'))));
        self::assertSame(1, $e->postsInMonth($workspace->id, new DateTimeImmutable('2026-11-15 12:00:00', new DateTimeZone('UTC'))));
    }

    public function testMovingACountedPostWithinItsMonthCostsNothingButMovingIntoAFullMonthDoes(): void
    {
        [, $workspace] = $this->ownerWithWorkspace();
        $this->givePlan($workspace, 'free', ['posts_per_month' => 1]);
        $e = $this->entitlements();
        $inMonth = new DateTimeImmutable('2026-10-10 12:00:00', new DateTimeZone('UTC'));
        $this->db->execute(
            'INSERT INTO posts (public_id, workspace_id, status, base_text, scheduled_at, timezone, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))',
            ['01JABCDEFGHJKMNPQRSTVWXYZ2', $workspace->id, 'scheduled', 'x', '2026-10-12 12:00:00', 'UTC'],
        );

        // A new post in a full month: refused.
        try {
            $e->assertCanPlanPost($workspace->id, $inMonth);
            self::fail('the month is full');
        } catch (PlanLimitException $ex) {
            self::assertSame('posts', $ex->limit);
            self::assertStringContainsString('до 1 поста в месяц', $ex->getMessage());
        }
        // The counted post moves inside the month: free. Into another month: that month has room.
        $e->assertCanPlanPost($workspace->id, $inMonth, new DateTimeImmutable('2026-10-12 12:00:00', new DateTimeZone('UTC')));
        $e->assertCanPlanPost($workspace->id, $inMonth->modify('+1 month'), new DateTimeImmutable('2026-10-12 12:00:00', new DateTimeZone('UTC')));
        self::assertSame(0, $e->postsLeftInMonth($workspace->id, $inMonth));
        self::assertSame(1, $e->postsLeftInMonth($workspace->id, $inMonth->modify('+1 month')));
    }

    public function testMembersAreCountedTogetherWithOpenInvitations(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->givePlan($workspace, 'start');
        $now = $this->clock->now();
        $e = $this->entitlements();
        self::assertSame(1, $e->membersUsed($workspace->id, $now));
        $e->assertCanAddMember($workspace->id, $now);

        $this->db->execute('INSERT INTO invitations (public_id, workspace_id, email, role, token_hash, expires_at, created_at) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))', ['01JABCDEFGHJKMNPQRSTVWXYZ3', $workspace->id, 'open@example.com', 'editor', str_repeat('a', 64), gmdate('Y-m-d H:i:s', time() + 86400)]);
        $this->db->execute('INSERT INTO invitations (public_id, workspace_id, email, role, token_hash, expires_at, created_at) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))', ['01JABCDEFGHJKMNPQRSTVWXYZ4', $workspace->id, 'old@example.com', 'editor', str_repeat('b', 64), gmdate('Y-m-d H:i:s', time() - 86400)]);
        $this->db->execute('INSERT INTO invitations (public_id, workspace_id, email, role, token_hash, expires_at, revoked_at, created_at) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))', ['01JABCDEFGHJKMNPQRSTVWXYZ5', $workspace->id, 'gone@example.com', 'editor', str_repeat('c', 64), gmdate('Y-m-d H:i:s', time() + 86400)]);

        self::assertSame(2, $e->membersUsed($workspace->id, $now), 'the owner and one open invitation; expired and revoked ones do not count');
        $this->expectException(PlanLimitException::class);
        $e->assertCanAddMember($workspace->id, $now);
    }

    public function testMembersOfAnyRoleCount(): void
    {
        [, $workspace] = $this->ownerWithWorkspace();
        $this->givePlan($workspace, 'free');
        $this->memberOf($workspace, 'viewer@example.com', Role::Client);

        $this->expectException(PlanLimitException::class);
        $this->entitlements()->assertCanAddMember($workspace->id, $this->clock->now());
    }

    public function testTheNumberOfWorkspacesFollowsTheBestPlanOfTheOwner(): void
    {
        [$owner, $personal] = $this->ownerWithWorkspace();
        $e = $this->entitlements();
        // Trial of Pro: three workspaces. The personal one counts as the first.
        self::assertSame(3, $e->workspacesAllowed($owner->id));

        $this->givePlan($personal, 'free');
        self::assertSame(1, $e->workspacesAllowed($owner->id));
        try {
            $e->assertCanCreateWorkspace($owner->id);
            self::fail('Free owns one workspace');
        } catch (PlanLimitException $ex) {
            self::assertSame('workspaces', $ex->limit);
        }

        $this->givePlan($personal, 'agency');
        self::assertNull($e->workspacesAllowed($owner->id), 'unlimited');
        $e->assertCanCreateWorkspace($owner->id);
    }

    public function testExtraWorkspacesStartOnFree(): void
    {
        [$owner] = $this->ownerWithWorkspace();
        $extra = $this->app->container()->get(WorkspaceService::class)->create($owner, 'Второе');

        self::assertNotNull($extra);
        self::assertSame('free', $this->entitlements()->plan($extra->id)->code);
        self::assertFalse($this->subscription($extra)->isTrial(), 'the trial is for the first workspace only');
    }

    public function testFeaturesAreOnlyWhatThePlanListsAndTheMessageSaysWhy(): void
    {
        [, $workspace] = $this->ownerWithWorkspace();
        $this->givePlan($workspace, 'start');
        $e = $this->entitlements();

        self::assertTrue($e->hasFeature($workspace->id, 'slots'));
        self::assertFalse($e->hasFeature($workspace->id, 'approvals'));
        $e->assertFeature($workspace->id, 'slots', 'Слоты');
        try {
            $e->assertFeature($workspace->id, 'approvals', 'Согласование постов');
            self::fail('Start has no approvals');
        } catch (PlanLimitException $ex) {
            self::assertSame('feature:approvals', $ex->limit);
            self::assertStringContainsString('«Согласование постов» недоступно на тарифе «Старт»', $ex->getMessage());
        }
    }

    public function testUsageSummarisesEveryMeter(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->givePlan($workspace, 'free');
        $this->makeChannel($workspace, $owner);

        $usage = $this->entitlements()->usage($workspace->id, $this->clock->now());

        self::assertSame(['used' => 1, 'limit' => 2], $usage['channels']);
        self::assertSame(['used' => 0, 'limit' => 30], $usage['posts']);
        self::assertSame(['used' => 1, 'limit' => 1], $usage['members']);
        self::assertSame(['used' => 0, 'limit' => 500 * 1024 * 1024], $usage['storage']);
    }

    public function testUpToUsesTheCorrectRussianForm(): void
    {
        self::assertSame('канала', Entitlements::upTo(1, 'канала', 'каналов'));
        self::assertSame('каналов', Entitlements::upTo(2, 'канала', 'каналов'));
        self::assertSame('каналов', Entitlements::upTo(11, 'канала', 'каналов'));
        self::assertSame('канала', Entitlements::upTo(21, 'канала', 'каналов'));
        self::assertSame('каналов', Entitlements::upTo(30, 'канала', 'каналов'));
    }
}
