<?php

declare(strict_types=1);

namespace App\Tests\Feature\Approval;

use App\Domain\Approval\ApprovalWorkflow;
use App\Domain\Approval\GuestLinks;
use App\Domain\Approval\PostComments;
use App\Domain\Post\PostStatus;
use App\Domain\Workspace\Role;
use App\Domain\Workspace\ChannelAccessRepository;
use App\Kernel\Exception\HttpException;
use App\Tests\Support\BillingTestCase;

/** Approval bypass, revision races, guest capability/PIN security and existing publishing regressions. */
final class ApprovalTest extends BillingTestCase
{
    /** @return array{\App\Domain\Workspace\WorkspaceContext,\App\Domain\Workspace\WorkspaceContext,\App\Domain\Post\Post,\App\Domain\Channel\Channel} */
    private function fixture(): array
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->payWithFake($workspace, $owner);
        $ctx = $this->contextFor($workspace, $owner);
        $author = $this->memberOf($workspace, 'approval-author@example.com', Role::Author, 'Автор');
        $writer = $this->contextFor($workspace, $author);
        $channel = $this->fakeChannel($workspace, $owner);
        $this->workflow()->configure($ctx, true, []);
        $post = $this->service()->saveDraft($writer, null, $this->draft('Original text', [$channel]));
        return [$ctx, $writer, $post, $channel];
    }
    private function workflow(): ApprovalWorkflow
    {
        return $this->app->container()->get(ApprovalWorkflow::class);
    }
    private function links(): GuestLinks
    {
        return $this->app->container()->get(GuestLinks::class);
    }
    private function authorLogin(int $id): void
    {
        $user = $this->app->container()->get(\App\Domain\User\UserRepository::class)->find($id) ?? throw new \LogicException();
        $this->actAs($user);
    }
    public function testAuthorCannotScheduleByDirectPostOrServiceAndEditorCannotBypassApproval(): void
    {
        [$ctx, $writer, $post, $channel] = $this->fixture();
        $this->authorLogin($writer->userId);
        $url = '/w/' . $ctx->workspacePublicId . '/posts/' . $post->publicId;
        self::assertSame(403, $this->post($url, ['text' => 'Original text', 'intent' => 'now', 'channels' => [$channel->publicId]])->status);
        try {
            $this->service()->schedule($ctx, $post, $this->draft('Original text', [$channel]), $this->in('+1 hour'));
            self::fail('Unapproved author post must not schedule');
        } catch (\App\Domain\Post\PostException $e) {
            self::assertFalse($e->forbidden);
        }
        self::assertSame(0, $this->db->table('publications')->count());
        self::assertSame(PostStatus::Draft, $this->posts()->find($ctx, $post->publicId)?->status);
    }
    public function testSubmitApproveScheduleAndPublicationUseExistingQueueOnce(): void
    {
        [$ctx, $writer, $post, $channel] = $this->fixture();
        $this->workflow()->submit($writer, $post);
        $post = $this->posts()->find($ctx, $post->publicId) ?? throw new \LogicException();
        self::assertSame(PostStatus::PendingApproval, $post->status);
        $revision = $this->workflow()->revision($ctx, $post);
        $this->workflow()->decide($ctx, $post, $revision, true);
        $this->workflow()->decide($ctx, $post, $revision, true);
        self::assertSame(1, $this->db->table('approvals')->count());
        $post = $this->posts()->find($ctx, $post->publicId) ?? throw new \LogicException();
        self::assertSame(PostStatus::Approved, $post->status);
        $post = $this->service()->schedule($ctx, $post, $this->draft('Original text', [$channel]), $this->in('+1 minute'));
        self::assertSame(PostStatus::Scheduled, $post->status);
        $this->clock->advance(61);
        $this->drain();
        $this->drain();
        self::assertCount(1, $this->fake->published);
        self::assertSame(PostStatus::Published, $this->posts()->find($ctx, $post->publicId)?->status);
    }
    public function testIdenticalAutosavePreservesApprovalButEditingResetsItAndKeepsVersions(): void
    {
        [$ctx, $writer, $post, $channel] = $this->fixture();
        $this->workflow()->submit($writer, $post);
        $revision = $this->workflow()->revision($ctx, $post);
        $this->workflow()->decide($ctx, $post, $revision, true);
        $approved = $this->posts()->find($ctx, $post->publicId) ?? throw new \LogicException();
        $same = $this->service()->saveDraft($writer, $approved, $this->draft('Original text', [$channel]), false);
        self::assertSame(PostStatus::Approved, $same->status);
        self::assertSame(1, $this->db->table('post_versions')->count());
        $edited = $this->service()->saveDraft($writer, $same, $this->draft('Changed text', [$channel]));
        self::assertSame(PostStatus::PendingApproval, $edited->status);
        self::assertSame(2, $this->db->table('post_versions')->count());
        self::assertSame(1, $this->db->table('approvals')->count(), 'History retained');
        try {
            $this->workflow()->decide($ctx, $edited, $revision, true);
            self::fail('Stale decision must fail');
        } catch (HttpException $e) {
            self::assertSame(409, $e->status);
        }
        $newRevision = $this->workflow()->revision($ctx, $edited);
        $this->workflow()->decide($ctx, $edited, $newRevision, false);
        self::assertSame(PostStatus::ChangesRequested, $this->posts()->find($ctx, $post->publicId)?->status);
        self::assertNotEmpty($this->workflow()->versions($ctx, $edited)[0]['diff']);
    }
    public function testChangedCandidateCannotSneakThroughScheduleWithOldApproval(): void
    {
        [$ctx, $writer, $post, $channel] = $this->fixture();
        $this->workflow()->submit($writer, $post);
        $this->workflow()->decide($ctx, $post, $this->workflow()->revision($ctx, $post), true);
        $post = $this->posts()->find($ctx, $post->publicId) ?? throw new \LogicException();
        try {
            $this->service()->schedule($ctx, $post, $this->draft('Different submitted text', [$channel]), $this->in('+1 hour'));
            self::fail('New content needs new approval');
        } catch (\App\Domain\Post\PostException $e) {
            self::assertStringContainsString('не согласован', $e->getMessage());
        }
        self::assertSame('Original text', $this->posts()->find($ctx, $post->publicId)?->baseText);
        self::assertSame(0, $this->db->table('publications')->count());
    }
    public function testCustomApproversAndSelfApprovalAndWorkspaceIsolation(): void
    {
        [$ctx, $writer, $post] = $this->fixture();
        $this->workflow()->configure($ctx, true, [$writer->memberPublicId]);
        $this->workflow()->submit($writer, $post);
        self::assertFalse($this->workflow()->canApprove($writer, $post));
        self::assertFalse($this->workflow()->canApprove($ctx, $post));
        [$other, $workspace] = $this->ownerWithWorkspace('approval-outsider@example.com');
        $outsider = $this->contextFor($workspace, $other);
        self::assertFalse($this->workflow()->canApprove($outsider, $post));
        $this->actAs($other);
        self::assertSame(404, $this->get('/w/' . $ctx->workspacePublicId . '/approvals')->status);
        self::assertSame(404, $this->post('/w/' . $ctx->workspacePublicId . '/posts/' . $post->publicId . '/comments', ['body' => 'attack'])->status);
    }
    public function testGuestPreviewApproveCommentsAndNoInternalDiscussionLeak(): void
    {
        [$ctx, $writer, $post] = $this->fixture();
        $this->workflow()->submit($writer, $post);
        $comments = $this->app->container()->get(PostComments::class);
        $comments->add($ctx, $post, 'Private internal discussion');
        $url = $this->links()->create($ctx, [$post->publicId], 24);
        self::assertSame(200, $this->get($url)->status);
        self::assertStringNotContainsString('Private internal discussion', $this->get($url)->body);
        self::assertSame(302, $this->post($url . '/posts/' . $post->publicId . '/approve', ['revision' => $this->workflow()->revision($ctx, $post)])->status);
        self::assertSame(PostStatus::Approved, $this->posts()->find($ctx, $post->publicId)?->status);
        self::assertSame(302, $this->post($url . '/posts/' . $post->publicId . '/comments', ['body' => '<script>attack</script>'])->status);
        self::assertStringContainsString('&lt;script&gt;attack&lt;/script&gt;', $this->get($url)->body);
        self::assertSame('no-store', $this->get($url)->headers['Cache-Control']);
        $stored = $this->db->table('guest_links')->first() ?? throw new \LogicException();
        self::assertStringNotContainsString('guest:', $stored['token_hash']);
    }
    public function testForgedExpiredRevokedAndUnsharedPostsAreRejected(): void
    {
        [$ctx, $writer, $post] = $this->fixture();
        $url = $this->links()->create($ctx, [$post->publicId], 1);
        self::assertSame(404, $this->get(substr($url, 0, -2) . 'ZZ')->status);
        $otherPost = $this->service()->saveDraft($ctx, null, $this->draft('Unshared secret'));
        self::assertSame(404, $this->post($url . '/posts/' . $otherPost->publicId . '/comments', ['body' => 'attack'])->status);
        $link = $this->db->table('guest_links')->first() ?? throw new \LogicException();
        $this->links()->revoke($ctx, $link['public_id']);
        self::assertSame(404, $this->get($url)->status);
        $second = $this->links()->create($ctx, [$post->publicId], 1);
        $this->clock->advance(3601);
        self::assertSame(404, $this->get($second)->status);
    }
    public function testPinRequiresUnlockCsrfAndPerLinkAndIpLimits(): void
    {
        [$ctx, $writer, $post] = $this->fixture();
        $url = $this->links()->create($ctx, [$post->publicId], 24, '481516');
        self::assertStringNotContainsString('Original text', $this->get($url)->body);
        self::assertSame(403, $this->post($url . '/posts/' . $post->publicId . '/comments', ['body' => 'bypass'])->status);
        self::assertSame(419, $this->request('POST', $url . '/unlock', ['pin' => '481516'])->status);
        for ($i = 0; $i < 5; ++$i) {
            self::assertSame(403, $this->post($url . '/unlock', ['pin' => '0000'])->status);
        }
        self::assertSame(429, $this->post($url . '/unlock', ['pin' => '481516'])->status);
        $this->cookies = [];
        $newBrowser = $this->post($url . '/unlock', ['pin' => '481516']);
        self::assertSame(429, $newBrowser->status);
        $this->clock->advance(901);
        self::assertSame(302, $this->post($url . '/unlock', ['pin' => '481516'])->status);
        self::assertStringContainsString('Original text', $this->get($url)->body);
    }
    public function testGuestLinksRequireProAndWorkspaceUiCsrf(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $ctx = $this->contextFor($workspace, $owner);
        $subscription = $this->subscription($workspace);
        $this->subscriptionService()->dropToFree($subscription, 'trial', false);
        $post = $this->service()->saveDraft($ctx, null, $this->draft('Free draft'));
        try {
            $this->links()->create($ctx, [$post->publicId], 24);
            self::fail('Free cannot share');
        } catch (\App\Domain\Billing\PlanLimitException) {
            self::assertSame(0, $this->db->table('guest_links')->count());
        }
        $this->actAs($owner);
        self::assertSame(200, $this->get('/w/' . $ctx->workspacePublicId . '/approvals')->status);
        self::assertSame(419, $this->request('POST', '/w/' . $ctx->workspacePublicId . '/approvals/settings', ['required' => '1'])->status);
        self::assertStringContainsString('Согласование постов', $this->get('/w/' . $ctx->workspacePublicId . '/settings')->body);
    }
    public function testClientSeesOnlyAssignedPostChannelsAndCannotChangeDraft(): void
    {
        [$ctx, $writer, $post, $channel] = $this->fixture();
        $workspace = $this->workspaces->findById($ctx->workspaceId) ?? throw new \LogicException();
        $owner = $this->app->container()->get(\App\Domain\User\UserRepository::class)->find($ctx->userId) ?? throw new \LogicException();
        $secret = $this->fakeChannel($workspace, $owner, 'hidden', 'Hidden channel');
        $hiddenPost = $this->service()->saveDraft($ctx, null, $this->draft('Secret post', [$secret]));
        $mixed = $this->service()->saveDraft($ctx, null, $this->draft('Shared post', [$channel, $secret]));
        $client = $this->memberOf($workspace, 'approval-client@example.com', Role::Client);
        $this->app->container()->get(ChannelAccessRepository::class)->set($ctx, $client->id, [$channel->id]);
        $this->actAs($client);
        $base = '/w/' . $ctx->workspacePublicId;
        self::assertSame(200, $this->get($base . '/posts/' . $post->publicId)->status);
        self::assertSame(404, $this->get($base . '/posts/' . $hiddenPost->publicId)->status);
        self::assertStringNotContainsString('Hidden channel', $this->get($base . '/posts/' . $mixed->publicId)->body);
        self::assertSame(403, $this->post($base . '/posts/' . $post->publicId, ['text' => 'attack'])->status);
        self::assertSame(403, $this->post($base . '/approvals/links', ['posts' => [$post->publicId]])->status);
    }
    public function testThreadsMentionsVersionDiffAndNotificationPreferences(): void
    {
        [$ctx, $writer, $post] = $this->fixture();
        $this->db->table('telegram_links')->insert(['user_id' => $writer->userId, 'chat_id' => 555000, 'username' => null, 'linked_at' => \App\Support\DbTime::format($this->clock->now())]);
        $comments = $this->app->container()->get(PostComments::class);
        $comments->add($ctx, $post, 'Please check @' . $writer->memberPublicId);
        $thread = $comments->thread($ctx, $post);
        $comments->add($writer, $post, 'Reply', $thread[0]['public_id']);
        self::assertCount(2, $comments->thread($ctx, $post));
        self::assertSame($thread[0]['public_id'], $comments->thread($ctx, $post)[1]['parent']);
        $notices = $this->db->select("SELECT * FROM notifications WHERE type='approval' AND user_id=?", [$writer->userId]);
        self::assertNotEmpty($notices);
        self::assertSame(1, (int) $notices[0]['sent_email']);
        self::assertSame(1, (int) $notices[0]['sent_telegram']);
        $this->workflow()->submit($writer, $post);
        self::assertContains('approval.submitted', $this->auditActions($this->workspaces->findById($ctx->workspaceId) ?? throw new \LogicException()));
    }
    public function testGuestMediaRequiresLiveLinkAndNeverSharesOtherLibraryFiles(): void
    {
        [$ctx,$writer,$post,$channel] = $this->fixture();
        $picture = $this->libraryPicture($ctx);
        $secret = $this->libraryPicture($ctx, 'private.jpg', 80, 80);
        $post = $this->service()->saveDraft($writer, $post, $this->draft('Photo preview', [$channel], [$picture->publicId]));
        $url = $this->links()->create($ctx, [$post->publicId], 24);
        $this->cookies = [];
        $file = $url . '/posts/' . $post->publicId . '/media/' . $picture->publicId;
        self::assertSame(200, $this->get($file)->status);
        self::assertSame(404, $this->get($url . '/posts/' . $post->publicId . '/media/' . $secret->publicId)->status);
        $row = $this->db->table('guest_links')->first() ?? throw new \LogicException();
        $this->links()->revoke($ctx, $row['public_id']);
        self::assertSame(404, $this->get($file)->status);
    }

    public function testWhiteLabelIsAgencyOnlyAndPeriodLinksFreezeTheSelectedSet(): void
    {
        [$ctx,$writer,$post,$channel] = $this->fixture();
        $workspace = $this->workspaces->findById($ctx->workspaceId) ?? throw new \LogicException();
        $this->subscriptionService()->grant($ctx->workspaceId, $this->plans()->findByCode('agency') ?? throw new \LogicException(), \App\Domain\Billing\BillingPeriod::Month);
        $logo = $this->libraryPicture($ctx);
        $this->workflow()->configure($ctx, true, [], 'Agency Brand', '#123456', $logo->publicId);
        $this->workflow()->submit($writer, $post);
        $this->workflow()->decide($ctx, $post, $this->workflow()->revision($ctx, $post), true);
        $post = $this->posts()->find($ctx, $post->publicId) ?? throw new \LogicException();
        $scheduled = $this->service()->schedule($ctx, $post, $this->draft('Original text', [$channel]), $this->in('+1 hour'));
        // Derive the day from the post itself: "now + 1 hour" can cross local midnight depending on when the suite runs.
        $date = ($scheduled->scheduledAt ?? throw new \LogicException())->setTimezone(new \DateTimeZone($ctx->timezone))->format('Y-m-d');
        $url = $this->links()->create($ctx, [], 24, from:$date, to:$date);
        self::assertStringContainsString('Agency Brand', $this->get($url)->body);
        self::assertStringContainsString('#123456', $this->get($url . '/theme.css')->body);
        self::assertSame(200, $this->get($url . '/posts/brand/media/' . $logo->publicId)->status);
        self::assertCount(1, $this->links()->posts(substr($url, 3)));
        $this->service()->schedule($ctx, null, $this->draft('Added later', [$channel]), $this->in('+2 hours'));
        self::assertCount(1, $this->links()->posts(substr($url, 3)));
    }

    public function testGuestOldRevisionAndPlanDowngradeAndDeletedCreatorAreDenied(): void
    {
        [$ctx,$writer,$post,$channel] = $this->fixture();
        $this->workflow()->submit($writer, $post);
        $revision = $this->workflow()->revision($ctx, $post);
        $url = $this->links()->create($ctx, [$post->publicId], 24);
        $post = $this->posts()->find($ctx, $post->publicId) ?? throw new \LogicException();
        $this->service()->saveDraft($writer, $post, $this->draft('Updated', [$channel]));
        self::assertSame(409, $this->post($url . '/posts/' . $post->publicId . '/approve', ['revision' => $revision])->status);
        $workspace = $this->workspaces->findById($ctx->workspaceId) ?? throw new \LogicException();
        $this->subscriptionService()->dropToFree($this->subscription($workspace), 'trial', false);
        self::assertSame(404, $this->get($url)->status);
    }

    public function testRestoredOldTextStillNeedsANewApproval(): void
    {
        [$ctx,$writer,$post,$channel] = $this->fixture();
        $this->workflow()->submit($writer, $post);
        $this->workflow()->decide($ctx, $post, $this->workflow()->revision($ctx, $post), true);
        $p = $this->posts()->find($ctx, $post->publicId) ?? throw new \LogicException();
        $p = $this->service()->saveDraft($writer, $p, $this->draft('Edit', [$channel]));
        $p = $this->service()->saveDraft($writer, $p, $this->draft('Original text', [$channel]));
        self::assertSame(PostStatus::PendingApproval, $p->status);
        try {
            $this->service()->schedule($ctx, $p, $this->draft('Original text', [$channel]), $this->in('+1 hour'));
            self::fail('Reverting text cannot resurrect old approval');
        } catch (\App\Domain\Post\PostException) {
            // Refused as a form error, the old approval is not reused.
        }
        self::assertSame(0, $this->db->table('publications')->count());
    }

    public function testChangesToMediaOrOptionsInvalidateApproval(): void
    {
        [$ctx,$writer,$post,$channel] = $this->fixture();
        $this->workflow()->submit($writer, $post);
        $this->workflow()->decide($ctx, $post, $this->workflow()->revision($ctx, $post), true);
        $p = $this->posts()->find($ctx, $post->publicId) ?? throw new \LogicException();
        $picture = $this->libraryPicture($ctx);
        $p = $this->service()->saveDraft($writer, $p, $this->draft('Original text', [$channel], [$picture->publicId]));
        self::assertSame(PostStatus::PendingApproval, $p->status);
        $this->workflow()->decide($ctx, $p, $this->workflow()->revision($ctx, $p), true);
        $p = $this->posts()->find($ctx, $post->publicId) ?? throw new \LogicException();
        $p = $this->service()->saveDraft($writer, $p, $this->draft('Original text', [$channel], [$picture->publicId], new \App\Domain\Post\PostOptions(silent:true)));
        self::assertSame(PostStatus::PendingApproval, $p->status);
    }

    public function testApprovalNotificationsRespectOptOut(): void
    {
        [$ctx,$writer,$post] = $this->fixture();
        $settings = $this->app->container()->get(\App\Domain\Notification\NotificationSettings::class);
        $settings->save($writer->userId, ['approval' => ['email' => false,'telegram' => false]]);
        $this->workflow()->submit($writer, $post);
        self::assertSame(0, $this->db->table('notifications')->where('user_id', '=', $writer->userId)->where('type', '=', 'approval')->count());
        self::assertGreaterThan(0, $this->db->table('notifications')->where('user_id', '=', $ctx->userId)->where('type', '=', 'approval')->count());
    }

    public function testPinRedisFailureFailsClosed(): void
    {
        [$ctx,$writer,$post] = $this->fixture();
        $this->app->container()->instance(\App\Kernel\Security\RateLimiter::class, new \App\Kernel\Security\RateLimiter(new \Redis(), $this->clock));
        $url = $this->links()->create($ctx, [$post->publicId], 24, '123456');
        $this->get($url);
        self::assertSame(503, $this->post($url.'/unlock', ['pin' => '123456'])->status);
        self::assertStringNotContainsString('Original text', $this->get($url)->body);
    }

    public function testApprovalOperationalCountersAreTenantScoped(): void
    {
        [$ctx,$writer,$post] = $this->fixture();
        $this->workflow()->submit($writer, $post);
        $this->links()->create($ctx, [$post->publicId], 24);
        $stats = $this->app->container()->get(\App\Domain\Approval\ApprovalStats::class);
        self::assertSame(['pending' => 1,'approved' => 0,'changes' => 0,'comments' => 0,'links' => 1], $stats->forWorkspace($ctx->workspaceId));
        [, $other] = $this->ownerWithWorkspace('approval-stats-other@example.com');
        self::assertSame(['pending' => 0,'approved' => 0,'changes' => 0,'comments' => 0,'links' => 0], $stats->forWorkspace($other->id));
    }

    public function testCreatedSecretLinkIsDisplayedOnceAndNeverListedAgain(): void
    {
        [$ctx,$writer,$post] = $this->fixture();
        $this->authorLogin($ctx->userId);
        $base = '/w/'.$ctx->workspacePublicId.'/approvals';
        self::assertSame(302, $this->post($base.'/links', ['posts' => [$post->publicId],'ttl_hours' => '72'])->status);
        $first = $this->get($base);
        self::assertStringContainsString('Скопируйте гостевую ссылку', $first->body);
        self::assertSame(1, preg_match('~/g/[a-f0-9]{64}\.[A-Za-z0-9_-]{43}~', $first->body));
        self::assertSame(0, preg_match('~/g/[a-f0-9]{64}\.[A-Za-z0-9_-]{43}~', $this->get($base)->body));
    }

    public function testNamedReviewerCanApproveWithoutReceivingPublishRights(): void
    {
        [$ctx,$writer,$post,$channel] = $this->fixture();
        $ws = $this->workspaces->findById($ctx->workspaceId) ?? throw new \LogicException();
        $viewer = $this->memberOf($ws, 'named-reviewer@example.com', Role::Viewer, 'Согласующий');
        $reviewer = $this->contextFor($ws, $viewer);
        self::assertFalse($this->workflow()->canApprove($reviewer, $post));
        $this->workflow()->configure($ctx, true, [$reviewer->memberPublicId]);
        $this->workflow()->submit($writer, $post);
        $this->actAs($viewer);
        $base = '/w/'.$ctx->workspacePublicId.'/posts/'.$post->publicId;
        self::assertSame(302, $this->post($base.'/approval/decision', ['decision' => 'approved','revision' => $this->workflow()->revision($ctx, $post)])->status);
        self::assertSame(PostStatus::Approved, $this->posts()->find($ctx, $post->publicId)?->status);
        self::assertSame(403, $this->post($base, ['intent' => 'now','text' => 'attack','channels' => [$channel->publicId]])->status);
    }

    public function testGuestLinkOfNonApproverCannotApproveAndNeverOverridesRequestedChanges(): void
    {
        [$ctx, $writer, $post] = $this->fixture();
        $ws = $this->workspaces->findById($ctx->workspaceId) ?? throw new \LogicException();
        $editor = $this->contextFor($ws, $this->memberOf($ws, 'approval-editor@example.com', Role::Editor, 'Редактор'));
        $this->workflow()->configure($ctx, true, [$ctx->memberPublicId]);
        $this->workflow()->submit($writer, $post);
        $revision = $this->workflow()->revision($ctx, $post);
        $url = $this->links()->create($editor, [$post->publicId], 24);
        self::assertSame(403, $this->post($url . '/posts/' . $post->publicId . '/approve', ['revision' => $revision])->status);
        self::assertSame(PostStatus::PendingApproval, $this->posts()->find($ctx, $post->publicId)?->status);
        self::assertSame(0, $this->db->table('approvals')->count());

        $this->workflow()->decide($ctx, $post, $revision, false);
        $ownerUrl = $this->links()->create($ctx, [$post->publicId], 24);
        self::assertSame(409, $this->post($ownerUrl . '/posts/' . $post->publicId . '/approve', ['revision' => $revision])->status);
        self::assertSame(PostStatus::ChangesRequested, $this->posts()->find($ctx, $post->publicId)?->status);
    }

    public function testGuestSeesOnlyCommentsOfItsOwnLink(): void
    {
        [$ctx, $writer, $post] = $this->fixture();
        $first = $this->links()->create($ctx, [$post->publicId], 24);
        $second = $this->links()->create($ctx, [$post->publicId], 24);
        $this->post($first . '/posts/' . $post->publicId . '/comments', ['body' => 'Client A remark']);
        self::assertStringContainsString('Client A remark', $this->get($first)->body);
        self::assertStringNotContainsString('Client A remark', $this->get($second)->body);
    }

    public function testVersionDiffShowsMediaChangesAndEnablingPolicyCountsUnapprovedScheduledPosts(): void
    {
        [$ctx, $writer, $post, $channel] = $this->fixture();
        $picture = $this->libraryPicture($ctx);
        $this->service()->saveDraft($writer, $post, $this->draft('Original text', [$channel], [$picture->publicId]));
        $diff = $this->workflow()->versions($ctx, $post)[0]['diff'];
        self::assertStringContainsString($picture->publicId, implode(' ', array_column($diff, 'text')));
        self::assertSame(0, $this->workflow()->unapprovedScheduled($ctx->workspaceId));
    }

    public function testTransitGuestLinkRoutesSupportPreviewApproveCommentsAnalyticsAndLegacy(): void
    {
        $crypto = new \App\Kernel\Security\Crypto('isolated', [], new \App\Tests\Support\FakeIsolatedCrypto());
        $this->app->container()->instance(\App\Kernel\Security\Crypto::class, $crypto);
        [$ctx, $writer, $post] = $this->fixture();
        $this->workflow()->submit($writer, $post);
        $url = $this->links()->create($ctx, [$post->publicId], 24);
        self::assertMatchesRegularExpression('~^/g/[a-f0-9]{64}\\.t1_[A-Za-z0-9_-]+$~D', $url);
        self::assertSame(200, $this->get($url)->status);
        $this->db->execute("UPDATE subscriptions s JOIN plans p ON p.code='agency' SET s.plan_id=p.id WHERE s.workspace_id=?", [$ctx->workspaceId]);
        self::assertSame(200, $this->get($url . '/analytics')->status);
        self::assertSame(302, $this->post($url . '/posts/' . $post->publicId . '/comments', ['body' => 'Transit guest comment'])->status);
        self::assertStringContainsString('Transit guest comment', $this->get($url)->body);
        self::assertSame(302, $this->post($url . '/posts/' . $post->publicId . '/approve', ['revision' => $this->workflow()->revision($ctx, $post)])->status);
        self::assertSame(PostStatus::Approved, $this->posts()->find($ctx, $post->publicId)?->status);
        [$raw] = explode('.', substr($url, 3), 2);
        $legacy = new \App\Kernel\Security\Signer(new \App\Kernel\Security\Crypto('test', ['test' => \App\Tests\Support\TestEnv::KEY]), $this->clock);
        $oldUrl = '/g/' . $raw . '.' . $legacy->sign('guest:' . $raw);
        self::assertSame(200, $this->get($oldUrl)->status);
        self::assertSame(200, $this->get($oldUrl . '/analytics')->status);
        foreach (['t2_' . str_repeat('A', 60), 't1_' . str_repeat('A', 60), str_repeat('!', 43)] as $bad) {
            self::assertSame(404, $this->get('/g/' . $raw . '.' . $bad)->status);
        }
    }

}
