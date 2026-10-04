<?php

declare(strict_types=1);

namespace App\Tests\Integration\Post;

use App\Domain\Channel\ChannelStatus;
use App\Domain\Notification\NotificationSettings;
use App\Domain\Post\DeletePublicationJob;
use App\Domain\Post\PostOptions;
use App\Domain\Post\PostStatus;
use App\Domain\Post\PostRepository;
use App\Domain\Post\PublicationDeleter;
use App\Domain\Post\PublicationRepository;
use App\Domain\Post\PublicationScheduler;
use App\Domain\Post\PublicationStatus;
use App\Domain\Post\PublicationSystem;
use App\Domain\Post\PublishJob;
use App\Domain\Post\Publisher;
use App\Domain\Post\PostService;
use App\Domain\Post\PostValidator;
use App\Domain\Post\PublishRequestBuilder;
use App\Integrations\Social\Contracts\ErrorKind;
use App\Kernel\Queue\Worker;
use App\Tests\Support\PostTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(Publisher::class)]
#[CoversClass(PublicationSystem::class)]
#[CoversClass(PublicationScheduler::class)]
#[CoversClass(PublicationDeleter::class)]
#[CoversClass(PublishJob::class)]
#[CoversClass(DeletePublicationJob::class)]
#[CoversClass(PostService::class)]
#[CoversClass(PostRepository::class)]
#[CoversClass(PublicationRepository::class)]
#[CoversClass(PostValidator::class)]
#[CoversClass(PublishRequestBuilder::class)]
final class PublishingPipelineTest extends PostTestCase
{
    /**
     * @return array{\App\Domain\Workspace\WorkspaceContext, \App\Domain\Channel\Channel, \App\Domain\User\User}
     */
    private function setUpWorkspace(): array
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $channel = $this->fakeChannel($workspace, $owner);

        return [$this->contextFor($workspace, $owner), $channel, $owner];
    }

    private function pubStatus(int $publicationId): string
    {
        return (string) $this->db->select('SELECT status FROM publications WHERE id = ?', [$publicationId])[0]['status'];
    }

    private function postStatus(int $postId): string
    {
        return (string) $this->db->select('SELECT status FROM posts WHERE id = ?', [$postId])[0]['status'];
    }

    public function testAPostPlannedForLaterIsPublishedByTheWorkerAtItsTime(): void
    {
        [$context, $channel] = $this->setUpWorkspace();
        [$post, $publications] = $this->scheduled($context, [$channel], '+10 minutes');

        self::assertSame(PostStatus::Scheduled, $post->status);
        self::assertSame(PublicationStatus::Queued, $publications[0]->status);

        // Too early: nothing is queued yet, nothing is sent.
        self::assertSame(0, $this->drain());
        self::assertSame([], $this->fake->published);

        // Within the scheduler's horizon the job is created, but it waits for the planned moment.
        $this->clock->advance(9 * 60);
        $this->app->container()->get(PublicationScheduler::class)->enqueueDue();
        $worker = $this->app->container()->get(Worker::class);
        self::assertFalse($worker->runNext(PublishJob::QUEUE, 'w'), 'the job is delayed until the planned time');

        $this->clock->advance(61);
        self::assertTrue($worker->runNext(PublishJob::QUEUE, 'w'));
        self::assertCount(1, $this->fake->published);
        self::assertSame('Привет, <b>мир</b>!', $this->fake->requests[0]->text, 'the markup went out as HTML');

        $publication = $this->publications()->find($context, $publications[0]->publicId);
        self::assertNotNull($publication);
        self::assertSame(PublicationStatus::Sent, $publication->status);
        self::assertSame('1', $publication->externalPostId);
        self::assertSame(['1'], $publication->externalIds);
        self::assertNotNull($publication->externalUrl);
        self::assertSame(PostStatus::Published, $this->posts()->find($context, $post->publicId)?->status);
        self::assertContains('post.published', $this->auditActions($this->workspaces->findById($context->workspaceId)));
    }

    public function testPublishNowGoesOutWithoutWaitingForTheScheduler(): void
    {
        [$context, $channel] = $this->setUpWorkspace();
        $post = $this->service()->schedule($context, null, $this->draft('Срочно', [$channel]), $this->clock->now(), true);

        $worker = $this->app->container()->get(Worker::class);
        self::assertTrue($worker->runNext(PublishJob::QUEUE, 'w'));
        self::assertCount(1, $this->fake->published);
        self::assertSame(PostStatus::Published, $this->posts()->find($context, $post->publicId)?->status);
    }

    public function testTheSchedulerNeverCreatesTheSameJobTwice(): void
    {
        [$context, $channel] = $this->setUpWorkspace();
        $this->scheduled($context, [$channel], '+30 seconds');
        $scheduler = $this->app->container()->get(PublicationScheduler::class);

        self::assertSame(1, $scheduler->enqueueDue());
        self::assertSame(0, $scheduler->enqueueDue());
        self::assertSame(1, $this->db->select('SELECT COUNT(*) AS n FROM jobs WHERE queue = ?', [PublishJob::QUEUE])[0]['n']);
    }

    public function testALostJobIsCreatedAgain(): void
    {
        [$context, $channel] = $this->setUpWorkspace();
        $this->scheduled($context, [$channel], '+30 seconds');
        $scheduler = $this->app->container()->get(PublicationScheduler::class);
        $scheduler->enqueueDue();
        $this->db->execute('DELETE FROM jobs');

        $this->clock->advance(60 + PublicationScheduler::STALE_SECONDS);

        self::assertSame(1, $scheduler->enqueueDue());
    }

    public function testTwoWorkersRaceForOneJobAndOnlyOneCallsTheNetwork(): void
    {
        [$context, $channel] = $this->setUpWorkspace();
        [, $publications] = $this->scheduled($context, [$channel], '+1 minute');
        $this->clock->advance(61);
        $system = $this->system();

        $first = $system->claim($publications[0]->id);
        $second = $system->claim($publications[0]->id);

        self::assertNotNull($first);
        self::assertNull($second, 'the claim has exactly one winner');
        self::assertSame(1, $first->attempt);

        // The same through the real code path: the job runs twice, the network is called once.
        $this->db->execute('UPDATE publications SET status = ?, attempt = 0 WHERE id = ?', ['queued', $publications[0]->id]);
        $publisher = $this->app->container()->get(Publisher::class);
        $publisher->run($publications[0]->id);
        $publisher->run($publications[0]->id);
        self::assertCount(1, $this->fake->published);
    }

    public function testARedisLockKeepsASecondWorkerAwayEvenBeforeTheClaim(): void
    {
        [$context, $channel] = $this->setUpWorkspace();
        [, $publications] = $this->scheduled($context, [$channel], '+1 minute');
        $this->clock->advance(61);
        $redis = $this->app->container()->get(\Redis::class);
        $redis->set('publish:lock:' . $publications[0]->id, 'other-worker', ['nx', 'ex' => 60]);

        $this->app->container()->get(Publisher::class)->run($publications[0]->id);

        self::assertSame([], $this->fake->published);
        self::assertSame('queued', $this->pubStatus($publications[0]->id), 'nothing was claimed');
        self::assertSame('other-worker', $redis->get('publish:lock:' . $publications[0]->id), 'a foreign lock is never released');
    }

    public function testADueTimeInTheFutureCannotBeClaimed(): void
    {
        [$context, $channel] = $this->setUpWorkspace();
        [, $publications] = $this->scheduled($context, [$channel], '+10 minutes');

        self::assertNull($this->system()->claim($publications[0]->id));
        $this->app->container()->get(Publisher::class)->run($publications[0]->id);
        self::assertSame([], $this->fake->published);
    }

    public function testWorkerDyingRightAfterSendingLeavesUnknownAndNeverADuplicate(): void
    {
        [$context, $channel] = $this->setUpWorkspace();
        [$post, $publications] = $this->scheduled($context, [$channel], '+1 minute');
        $this->clock->advance(61);
        // The network accepted the post, then the process "dies" before anything is stored.
        $this->fake->afterSend = static function (): void {
            throw new \RuntimeException('simulated crash after sending');
        };

        $this->app->container()->get(Publisher::class)->run($publications[0]->id);

        self::assertCount(1, $this->fake->published);
        self::assertSame('unknown', $this->pubStatus($publications[0]->id));
        self::assertSame('failed', $this->postStatus($post->id));

        // Running it again (the job is redelivered, the scheduler ticks) must not send a second copy.
        $this->fake->afterSend = null;
        $this->clock->advance(7200);
        $this->app->container()->get(Publisher::class)->run($publications[0]->id);
        $this->app->container()->get(PublicationScheduler::class)->tick();
        $this->drain();
        self::assertCount(1, $this->fake->published);
        self::assertSame('unknown', $this->pubStatus($publications[0]->id));
    }

    public function testAPublicationLeftSendingByADeadWorkerBecomesUnknownAndTheOwnerIsTold(): void
    {
        [$context, $channel] = $this->setUpWorkspace();
        [$post, $publications] = $this->scheduled($context, [$channel], '+1 minute');
        $this->clock->advance(61);
        self::assertNotNull($this->system()->claim($publications[0]->id)); // the worker took it and died
        $scheduler = $this->app->container()->get(PublicationScheduler::class);

        self::assertSame(0, $scheduler->reapStuck(), 'a fresh claim is still somebody\'s work');
        $this->clock->advance(PublicationScheduler::STUCK_SECONDS + 5);
        self::assertSame(1, $scheduler->reapStuck());

        self::assertSame('unknown', $this->pubStatus($publications[0]->id));
        self::assertSame('failed', $this->postStatus($post->id));
        self::assertSame(1, $this->db->select('SELECT COUNT(*) AS n FROM notifications WHERE type = ?', ['publish_failed'])[0]['n']);
        self::assertSame([], $this->fake->published);
    }

    public function testATemporaryFailureIsRetriedWithBackoffAndThenGivesUp(): void
    {
        [$context, $channel] = $this->setUpWorkspace();
        [$post, $publications] = $this->scheduled($context, [$channel], '+1 minute');
        $this->clock->advance(61);
        $worker = $this->app->container()->get(Worker::class);
        $id = $publications[0]->id;

        $delays = [];
        foreach ([60, 300, 900, 3600] as $expected) {
            $this->fake->failNext(ErrorKind::Temporary, 'HTTP 502');
            $this->app->container()->get(PublicationScheduler::class)->enqueueDue();
            self::assertTrue($worker->runNext(PublishJob::QUEUE, 'w'));
            self::assertSame('queued', $this->pubStatus($id));
            $row = $this->db->select('SELECT run_at, attempt FROM publications WHERE id = ?', [$id])[0];
            $delays[] = (int) round((new \DateTimeImmutable((string) $row['run_at'], new \DateTimeZone('UTC')))->getTimestamp() - $this->clock->now()->getTimestamp());
            self::assertSame('publishing', $this->postStatus($post->id));
            $this->clock->advance($expected + 1);
        }
        self::assertSame([60, 300, 900, 3600], $delays);

        $this->fake->failNext(ErrorKind::Temporary, 'HTTP 502');
        self::assertTrue($worker->runNext(PublishJob::QUEUE, 'w'));
        self::assertSame('failed', $this->pubStatus($id), 'the fifth failure is final');
        self::assertSame('failed', $this->postStatus($post->id));
        self::assertSame([], $this->fake->published);
        self::assertCount(5, $this->publications()->attempts($context, $this->publications()->find($context, $publications[0]->publicId) ?? $publications[0]));
    }

    public function testATemporaryFailureThatRecoversPublishesOnce(): void
    {
        [$context, $channel] = $this->setUpWorkspace();
        [, $publications] = $this->scheduled($context, [$channel], '+1 minute');
        $this->clock->advance(61);
        $worker = $this->app->container()->get(Worker::class);

        $this->fake->failNext(ErrorKind::Temporary, 'timeout before sending');
        $this->app->container()->get(PublicationScheduler::class)->enqueueDue();
        $worker->runNext(PublishJob::QUEUE, 'w');
        $this->clock->advance(61);
        $worker->runNext(PublishJob::QUEUE, 'w');

        self::assertSame('sent', $this->pubStatus($publications[0]->id));
        self::assertCount(1, $this->fake->published);
    }

    public function testRateLimitingWaitsForRetryAfter(): void
    {
        [$context, $channel] = $this->setUpWorkspace();
        [, $publications] = $this->scheduled($context, [$channel], '+1 minute');
        $this->clock->advance(61);
        $this->fake->failNext(ErrorKind::RateLimited, 'Too Many Requests', 42);

        $this->app->container()->get(PublicationScheduler::class)->enqueueDue();
        $this->app->container()->get(Worker::class)->runNext(PublishJob::QUEUE, 'w');

        $row = $this->db->select('SELECT status, run_at FROM publications WHERE id = ?', [$publications[0]->id])[0];
        self::assertSame('queued', $row['status']);
        self::assertSame(42, (new \DateTimeImmutable((string) $row['run_at'], new \DateTimeZone('UTC')))->getTimestamp() - $this->clock->now()->getTimestamp());
    }

    public function testAPermanentFailureIsNotRetriedAndTheOwnerIsTold(): void
    {
        [$context, $channel] = $this->setUpWorkspace();
        [$post, $publications] = $this->scheduled($context, [$channel], '+1 minute');
        $this->clock->advance(61);
        $this->fake->failNext(ErrorKind::Permanent, 'message is too long');

        $this->app->container()->get(PublicationScheduler::class)->enqueueDue();
        $this->app->container()->get(Worker::class)->runNext(PublishJob::QUEUE, 'w');
        $this->clock->advance(7200);
        $this->drain();

        self::assertSame('failed', $this->pubStatus($publications[0]->id));
        self::assertSame('failed', $this->postStatus($post->id));
        self::assertSame([], $this->fake->published, 'no retry after a permanent refusal');
        $notification = $this->db->select('SELECT title, body FROM notifications WHERE type = ?', ['publish_failed'])[0];
        self::assertStringContainsString('Не удалось опубликовать', (string) $notification['title']);
        self::assertStringContainsString('Тестовая ошибка', (string) $notification['body']);
        $row = $this->db->select('SELECT error_message, error_detail FROM publications WHERE id = ?', [$publications[0]->id])[0];
        self::assertStringContainsString('Тестовая ошибка', (string) $row['error_message']);
        self::assertStringContainsString('message is too long', (string) $row['error_detail'], 'the technical detail is kept for administrators');
    }

    public function testAnAuthFailureFailsThePublicationAndMarksTheChannelBroken(): void
    {
        [$context, $channel] = $this->setUpWorkspace();
        [, $publications] = $this->scheduled($context, [$channel], '+1 minute');
        $this->clock->advance(61);
        $this->fake->failNext(ErrorKind::Auth, 'token revoked');

        $this->app->container()->get(PublicationScheduler::class)->enqueueDue();
        $this->app->container()->get(Worker::class)->runNext(PublishJob::QUEUE, 'w');

        self::assertSame('failed', $this->pubStatus($publications[0]->id));
        self::assertSame(ChannelStatus::Error->value, $this->db->select('SELECT status FROM channels WHERE id = ?', [$channel->id])[0]['status']);
        $mails = $this->db->select('SELECT COUNT(*) AS n FROM notifications WHERE type = ?', ['channel_problem'])[0]['n'];
        self::assertSame(1, $mails, 'the channel problem is reported once');
    }

    public function testAnUnknownOutcomeIsNeverRetriedByItself(): void
    {
        [$context, $channel] = $this->setUpWorkspace();
        [$post, $publications] = $this->scheduled($context, [$channel], '+1 minute');
        $this->clock->advance(61);
        $this->fake->failNext(ErrorKind::UnknownOutcome, 'connection reset after the request');

        $this->app->container()->get(PublicationScheduler::class)->enqueueDue();
        $this->app->container()->get(Worker::class)->runNext(PublishJob::QUEUE, 'w');
        $this->clock->advance(86400);
        $this->drain();

        self::assertSame('unknown', $this->pubStatus($publications[0]->id));
        self::assertSame('failed', $this->postStatus($post->id));
        self::assertSame([], $this->fake->published);
        self::assertStringContainsString('Нужно проверить', (string) $this->db->select('SELECT title FROM notifications WHERE type = ?', ['publish_failed'])[0]['title']);
    }

    public function testAPausedOrBrokenChannelFailsWithoutCallingTheNetwork(): void
    {
        [$context, $channel] = $this->setUpWorkspace();
        [, $publications] = $this->scheduled($context, [$channel], '+1 minute');
        $this->db->execute('UPDATE channels SET status = ? WHERE id = ?', ['paused', $channel->id]);
        $this->clock->advance(61);

        $this->drain();

        self::assertSame('failed', $this->pubStatus($publications[0]->id));
        self::assertSame([], $this->fake->published);
        self::assertStringContainsString('на паузе', (string) $this->db->select('SELECT error_message FROM publications WHERE id = ?', [$publications[0]->id])[0]['error_message']);
    }

    public function testTheChannelIsCheckedRightBeforePublishing(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $channel = $this->fakeChannel($workspace, $owner, 'broken-1');
        $context = $this->contextFor($workspace, $owner);
        $this->db->execute('UPDATE channels SET last_health_at = ? WHERE id = ?', ['2000-01-01 00:00:00', $channel->id]);
        [, $publications] = $this->scheduled($context, [$channel], '+1 minute');
        $this->clock->advance(61);

        $this->drain();

        self::assertSame('failed', $this->pubStatus($publications[0]->id));
        self::assertSame('error', $this->db->select('SELECT status FROM channels WHERE id = ?', [$channel->id])[0]['status']);
        self::assertSame([], $this->fake->published);
    }

    public function testOnePostToTwoChannelsGivesAPartialResult(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $a = $this->fakeChannel($workspace, $owner, 'fake-a', 'A');
        $b = $this->fakeChannel($workspace, $owner, 'fake-b', 'B');
        $context = $this->contextFor($workspace, $owner);
        [$post, $publications] = $this->scheduled($context, [$a, $b], '+1 minute');
        $this->clock->advance(61);
        $worker = $this->app->container()->get(Worker::class);
        $this->app->container()->get(PublicationScheduler::class)->enqueueDue();

        $worker->runNext(PublishJob::QUEUE, 'w');
        self::assertSame('publishing', $this->postStatus($post->id), 'one is out, one is still waiting');
        $this->fake->failNext(ErrorKind::Permanent, 'refused');
        $worker->runNext(PublishJob::QUEUE, 'w');

        self::assertSame('partially_failed', $this->postStatus($post->id));
        self::assertCount(1, $this->fake->published);
    }

    public function testDeleteAfterRemovesThePostFromTheNetworkWhenItsTimeComes(): void
    {
        [$context, $channel] = $this->setUpWorkspace();
        $post = $this->service()->schedule($context, null, $this->draft('Ненадолго', [$channel], [], new PostOptions(deleteAfterMinutes: 60)), $this->in('+1 minute'));
        $publication = $this->publications()->forPost($context, $post)[0];
        $this->clock->advance(61);
        $this->drain();
        $sent = $this->publications()->find($context, $publication->publicId);
        self::assertNotNull($sent);
        self::assertNotNull($sent->deleteAt);

        $this->clock->advance(3500);
        self::assertSame(0, $this->app->container()->get(PublicationScheduler::class)->enqueueDeletions(), 'not yet');
        $this->clock->advance(200);
        self::assertSame(1, $this->app->container()->get(PublicationScheduler::class)->enqueueDeletions());
        $worker = $this->app->container()->get(Worker::class);
        self::assertTrue($worker->runNext(PublishJob::QUEUE, 'w'));

        self::assertSame(['1'], $this->fake->deleted);
        self::assertNotNull($this->publications()->find($context, $publication->publicId)?->deletedAt);
        self::assertSame(0, $this->app->container()->get(PublicationScheduler::class)->enqueueDeletions(), 'deleted once');
    }

    public function testPinAndFirstCommentFollowASuccessfulPublication(): void
    {
        [$context, $channel] = $this->setUpWorkspace();
        $this->service()->schedule($context, null, $this->draft('Важное', [$channel], [], new PostOptions(pin: true, firstComment: 'Ссылка: [тут](https://a.ru)')), $this->clock->now(), true);

        $this->app->container()->get(Worker::class)->runNext(PublishJob::QUEUE, 'w');

        self::assertSame(['1'], $this->fake->pinned);
        self::assertSame([['id' => '1', 'text' => 'Ссылка: тут (https://a.ru)']], $this->fake->comments);
    }

    public function testAFailedPinDoesNotTurnAPublishedPostIntoAFailedOne(): void
    {
        [$context, $channel] = $this->setUpWorkspace();
        $post = $this->service()->schedule($context, null, $this->draft('Важное', [$channel], [], new PostOptions(pin: true)), $this->clock->now(), true);
        $this->fake->afterSend = function (): void {
            $this->fake->failNext(ErrorKind::Permanent, 'not enough rights to pin');
        };

        $this->app->container()->get(Worker::class)->runNext(PublishJob::QUEUE, 'w');

        self::assertSame(PostStatus::Published, $this->posts()->find($context, $post->publicId)?->status);
        $outcomes = array_column($this->db->select('SELECT outcome FROM publication_attempts ORDER BY id'), 'outcome');
        self::assertSame(['sent', 'pin_failed'], $outcomes);
    }

    public function testDisconnectingAChannelCancelsItsPlannedPosts(): void
    {
        [$context, $channel] = $this->setUpWorkspace();
        [$post, $publications] = $this->scheduled($context, [$channel], '+1 hour');
        $other = $this->fakeChannel($this->workspaces->findById($context->workspaceId) ?? throw new \LogicException(), $this->app->container()->get(\App\Domain\User\UserRepository::class)->find($context->userId) ?? throw new \LogicException(), 'fake-2', 'Другой');
        $channelService = $this->app->container()->get(\App\Domain\Channel\ChannelService::class);

        $channelService->remove($context, $channel);

        self::assertSame('cancelled', $this->pubStatus($publications[0]->id));
        self::assertSame('cancelled', $this->postStatus($post->id));
        $row = $this->db->select('SELECT channel_id FROM publications WHERE id = ?', [$publications[0]->id])[0];
        self::assertNull($row['channel_id'], 'the history stays, without the channel');
        self::assertNotNull($other);
        $this->clock->advance(7200);
        $this->drain();
        self::assertSame([], $this->fake->published);
    }

    public function testThePublishedPostIsReportedToItsAuthorOnlyWhenTheyAskedForIt(): void
    {
        [$context, $channel, $owner] = $this->setUpWorkspace();
        $this->service()->schedule($context, null, $this->draft('Раз', [$channel]), $this->clock->now(), true);
        $this->app->container()->get(Worker::class)->runNext(PublishJob::QUEUE, 'w');
        self::assertSame(0, $this->db->select('SELECT COUNT(*) AS n FROM notifications WHERE type = ?', ['publish_ok'])[0]['n'], 'off by default');

        $this->app->container()->get(NotificationSettings::class)->save($owner->id, ['publish_ok' => ['email' => true, 'telegram' => false]]);
        $this->service()->schedule($context, null, $this->draft('Два', [$channel]), $this->clock->now(), true);
        $this->app->container()->get(Worker::class)->runNext(PublishJob::QUEUE, 'w');

        self::assertSame(1, $this->db->select('SELECT COUNT(*) AS n FROM notifications WHERE type = ?', ['publish_ok'])[0]['n']);
        $this->drainQueue();
        self::assertNotEmpty($this->mailer->sent);
    }

    public function testThePublishLatencyIsLogged(): void
    {
        [$context, $channel] = $this->setUpWorkspace();
        [, $publications] = $this->scheduled($context, [$channel], '+1 minute');
        $this->clock->advance(65);

        $this->drain();

        $row = $this->db->select('SELECT due_at, sent_at FROM publications WHERE id = ?', [$publications[0]->id])[0];
        self::assertSame('sent', $this->pubStatus($publications[0]->id));
        self::assertNotNull($row['sent_at']);
    }
}
