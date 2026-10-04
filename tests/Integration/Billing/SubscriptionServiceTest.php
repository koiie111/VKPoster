<?php

declare(strict_types=1);

namespace App\Tests\Integration\Billing;

use App\Domain\Billing\BillingException;
use App\Domain\Billing\BillingPeriod;
use App\Domain\Billing\SubscriptionService;
use App\Domain\Billing\SubscriptionStatus;
use App\Domain\Channel\ChannelRepository;
use App\Domain\Channel\ChannelStatus;
use App\Domain\Post\PostStatus;
use App\Domain\Post\PublicationStatus;
use App\Tests\Support\BillingTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SubscriptionService::class)]
final class SubscriptionServiceTest extends BillingTestCase
{
    public function testTheTrialLastsFourteenDaysOfProAndIsRecordedOnTheWorkspace(): void
    {
        [, $workspace] = $this->ownerWithWorkspace();

        $subscription = $this->subscription($workspace);

        self::assertSame(SubscriptionStatus::Trialing, $subscription->status);
        self::assertSame(3, $subscription->planId === $this->plans()->findByCode('pro')?->id ? 3 : 0);
        self::assertSame($this->clock->now()->modify('+14 days')->format('Y-m-d H:i'), $subscription->trialEndsAt?->format('Y-m-d H:i'));
        self::assertSame($subscription->planId, (int) $this->db->select('SELECT plan_id FROM workspaces WHERE id = ?', [$workspace->id])[0]['plan_id']);
        self::assertContains('billing.trial_started', $this->auditActions($workspace));
    }

    public function testStartingAnExistingSubscriptionAgainChangesNothing(): void
    {
        [, $workspace] = $this->ownerWithWorkspace();
        $first = $this->subscription($workspace);

        $again = $this->subscriptionService()->startTrial($workspace->id);
        $free = $this->subscriptionService()->startFree($workspace->id);

        self::assertSame($first->publicId, $again->publicId);
        self::assertSame($first->publicId, $free->publicId);
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS c FROM subscriptions WHERE workspace_id = ?', [$workspace->id])[0]['c']);
    }

    public function testFallingToFreePausesTheNewestChannelsAndCancelsTheirPostsButDeletesNothing(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $context = $this->contextFor($workspace, $owner);
        $channels = [];
        foreach (['A', 'B', 'C', 'D'] as $i => $name) {
            $channels[] = $this->fakeChannel($workspace, $owner, 'fake-' . $name, 'Канал ' . $name);
            $this->clock->advance(5);
        }
        [$post] = $this->scheduled($context, [$channels[0], $channels[3]], '+1 day');

        $paused = $this->subscriptionService()->dropToFree($this->subscription($workspace), 'trial');

        self::assertSame(2, $paused);
        $repo = $this->app->container()->get(ChannelRepository::class);
        $after = array_map(static fn ($c) => $repo->findById($context, $c->id)?->status, $channels);
        self::assertSame([ChannelStatus::Active, ChannelStatus::Active, ChannelStatus::Paused, ChannelStatus::Paused], $after, 'the two oldest stay, the newest are paused');
        self::assertStringContainsString('Free', (string) $repo->findById($context, $channels[3]->id)?->lastError);
        self::assertSame(4, $repo->count($context), 'nothing is deleted');
        $statuses = array_map(static fn ($p) => $p->status, $this->publications()->forPost($context, $post));
        self::assertContains(PublicationStatus::Cancelled, $statuses, 'the post planned for a paused channel is cancelled with a reason');
        self::assertContains(PublicationStatus::Queued, $statuses, 'the one planned for a channel that stays is untouched');
        self::assertSame(PostStatus::Scheduled, $this->posts()->find($context, $post->publicId)?->status);
        self::assertContains('billing.channels_paused', $this->auditActions($workspace));
    }

    public function testFreeIsASubscriptionWithoutDatesAndTheTrialIsOver(): void
    {
        [, $workspace] = $this->ownerWithWorkspace();

        $this->subscriptionService()->dropToFree($this->subscription($workspace), 'trial');

        $s = $this->subscription($workspace);
        self::assertSame('free', $this->plans()->find($s->planId)?->code);
        self::assertSame(SubscriptionStatus::Active, $s->status);
        self::assertNull($s->currentPeriodEnd);
        self::assertNull($s->trialEndsAt);
        self::assertFalse($s->hasPaidPeriod());
        self::assertContains('Пробный период закончился', $this->mailSubjects());
    }

    public function testRenewalCanBeCancelledAndResumed(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->payWithFake($workspace, $owner);
        $s = $this->subscription($workspace);
        self::assertTrue($s->autoRenews());
        self::assertNotNull($s->nextRenewalAttemptAt);

        $this->subscriptionService()->cancelRenewal($workspace->id, $owner->id);

        $cancelled = $this->subscription($workspace);
        self::assertTrue($cancelled->cancelAtPeriodEnd);
        self::assertFalse($cancelled->autoRenews());
        self::assertNull($cancelled->nextRenewalAttemptAt);

        $this->subscriptionService()->resumeRenewal($workspace->id, $owner->id);

        $resumed = $this->subscription($workspace);
        self::assertFalse($resumed->cancelAtPeriodEnd);
        self::assertTrue($resumed->autoRenews());
        self::assertNotNull($resumed->nextRenewalAttemptAt);
        self::assertEqualsCanonicalizing(['billing.renewal_canceled', 'billing.renewal_resumed'], array_values(array_intersect($this->auditActions($workspace), ['billing.renewal_canceled', 'billing.renewal_resumed'])));
    }

    public function testCancellingOnFreeOrATrialDoesNothing(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();

        $this->subscriptionService()->cancelRenewal($workspace->id, $owner->id);

        self::assertFalse($this->subscription($workspace)->cancelAtPeriodEnd, 'a trial has no period to cancel');
    }

    public function testADowngradeCanBeBookedAndTakenBack(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->payWithFake($workspace, $owner, 'pro');
        $start = $this->plans()->findByCode('start') ?? self::fail('start');

        $this->subscriptionService()->scheduleChange($workspace->id, $start, BillingPeriod::Month, $owner->id);

        $booked = $this->subscription($workspace);
        self::assertSame($start->id, $booked->pendingPlanId);
        self::assertSame(BillingPeriod::Month, $booked->pendingPeriod);
        self::assertSame('pro', $this->plans()->find($booked->planId)?->code, 'nothing changes until the period ends');

        $this->subscriptionService()->clearScheduledChange($workspace->id, $owner->id);

        self::assertNull($this->subscription($workspace)->pendingPlanId);
    }

    public function testBookingAChangeNeedsAPaidPeriod(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();

        $this->expectException(BillingException::class);
        $this->subscriptionService()->scheduleChange($workspace->id, $this->plans()->findByCode('start') ?? self::fail('start'), BillingPeriod::Month, $owner->id);
    }

    public function testGrantGivesAPlanWithoutAPaymentAndIsAudited(): void
    {
        [, $workspace] = $this->ownerWithWorkspace();
        $agency = $this->plans()->findByCode('agency') ?? self::fail('agency');

        $this->subscriptionService()->grant($workspace->id, $agency, BillingPeriod::Year);

        $s = $this->subscription($workspace);
        self::assertSame($agency->id, $s->planId);
        self::assertSame($this->clock->now()->modify('+1 year')->format('Y-m-d'), $s->currentPeriodEnd?->format('Y-m-d'));
        self::assertNull($s->trialEndsAt);
        self::assertContains('billing.plan_granted', $this->auditActions($workspace));
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM invoices')[0]['c'], 'no invoice, no money');
    }

    public function testASmallerPlanKeepsMembersAndFilesButStopsNewOnes(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->memberOf($workspace, 'a@example.com', \App\Domain\Workspace\Role::Editor);
        $this->memberOf($workspace, 'b@example.com', \App\Domain\Workspace\Role::Editor);

        $this->subscriptionService()->dropToFree($this->subscription($workspace), 'trial', false);

        self::assertSame(3, (int) $this->db->select('SELECT COUNT(*) AS c FROM workspace_members WHERE workspace_id = ?', [$workspace->id])[0]['c'], 'nobody is removed');
        $this->expectException(\App\Domain\Billing\PlanLimitException::class);
        $this->app->container()->get(\App\Domain\Billing\Entitlements::class)->assertCanAddMember($workspace->id, $this->clock->now());
    }
}
