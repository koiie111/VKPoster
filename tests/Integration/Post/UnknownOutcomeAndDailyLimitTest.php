<?php

declare(strict_types=1);

namespace App\Tests\Integration\Post;

use App\Domain\Post\PostException;
use App\Domain\Post\PostService;
use App\Domain\Post\PublicationScheduler;
use App\Domain\Post\Publisher;
use App\Domain\Post\PublishJob;
use App\Integrations\Social\Contracts\ErrorKind;
use App\Integrations\Social\Contracts\PublishRequest;
use App\Integrations\Social\Contracts\PublishResult;
use App\Kernel\Queue\Worker;
use App\Tests\Support\PostTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(Publisher::class)]
#[CoversClass(PostService::class)]
final class UnknownOutcomeAndDailyLimitTest extends PostTestCase
{
    /**
     * @return array{\App\Domain\Workspace\WorkspaceContext, \App\Domain\Channel\Channel}
     */
    private function workspace(): array
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();

        return [$this->contextFor($workspace, $owner), $this->fakeChannel($workspace, $owner)];
    }

    private function runDue(): void
    {
        $this->clock->advance(61);
        $this->app->container()->get(PublicationScheduler::class)->enqueueDue();
        $this->app->container()->get(Worker::class)->runNext(PublishJob::QUEUE, 'w');
    }

    private function pubStatus(int $id): string
    {
        return (string) $this->db->select('SELECT status FROM publications WHERE id = ?', [$id])[0]['status'];
    }

    public function testAPostFoundOnTheWallAfterAnUnknownOutcomeCountsAsPublished(): void
    {
        [$context, $channel] = $this->workspace();
        [$post, $publications] = $this->scheduled($context, [$channel], '+1 minute');
        $this->fake->failNext(ErrorKind::UnknownOutcome, 'connection reset after the request');
        $this->fake->verify = static fn (PublishRequest $request, string $channelId): PublishResult => new PublishResult('4400', 'https://fake.invalid/p/4400', ['4400']);

        $this->runDue();

        self::assertSame('sent', $this->pubStatus($publications[0]->id));
        $publication = $this->publications()->find($context, $publications[0]->publicId);
        self::assertSame('4400', $publication?->externalPostId);
        self::assertSame('published', (string) $this->db->select('SELECT status FROM posts WHERE id = ?', [$post->id])[0]['status']);
        self::assertSame([], $this->fake->published, 'nothing was published a second time');
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS n FROM notifications WHERE type = ?', ['publish_failed'])[0]['n'], 'the owner is not bothered');
    }

    public function testAPostThatIsNotOnTheWallStaysUnknown(): void
    {
        [$context, $channel] = $this->workspace();
        [, $publications] = $this->scheduled($context, [$channel], '+1 minute');
        $this->fake->failNext(ErrorKind::UnknownOutcome);
        $looked = 0;
        $this->fake->verify = static function () use (&$looked): ?PublishResult {
            ++$looked;

            return null;
        };

        $this->runDue();

        self::assertSame(1, $looked);
        self::assertSame('unknown', $this->pubStatus($publications[0]->id));
    }

    public function testAWallThatCannotBeReadLeavesTheOutcomeUnknown(): void
    {
        [$context, $channel] = $this->workspace();
        [, $publications] = $this->scheduled($context, [$channel], '+1 minute');
        $this->fake->failNext(ErrorKind::UnknownOutcome);
        $this->fake->verify = static function (): never {
            throw new \RuntimeException('wall unreachable');
        };

        $this->runDue();

        self::assertSame('unknown', $this->pubStatus($publications[0]->id));
    }

    public function testAnUnexpectedCrashDuringTheCallIsCheckedToo(): void
    {
        [$context, $channel] = $this->workspace();
        [, $publications] = $this->scheduled($context, [$channel], '+1 minute');
        $this->fake->afterSend = static function (): never {
            throw new \RuntimeException('worker lost its database');
        };
        $this->fake->verify = static fn (): PublishResult => new PublishResult('7', null, ['7']);

        $this->runDue();

        self::assertSame('sent', $this->pubStatus($publications[0]->id));
    }

    public function testOrdinaryFailuresAreNotChecked(): void
    {
        [$context, $channel] = $this->workspace();
        [, $publications] = $this->scheduled($context, [$channel], '+1 minute');
        $this->fake->failNext(ErrorKind::Permanent);
        $this->fake->verify = static function (): never {
            throw new \LogicException('a refusal must not trigger the lookup');
        };

        $this->runDue();

        self::assertSame('failed', $this->pubStatus($publications[0]->id));
    }

    public function testAChannelCannotBePlannedPastTheDailyLimit(): void
    {
        [$context, $channel] = $this->workspace();
        $this->fake->dailyLimit = 2;
        $day = $this->in('+2 days');
        $first = $this->service()->schedule($context, null, $this->draft('Один', [$channel]), $day->setTime(9, 0));
        $this->service()->schedule($context, null, $this->draft('Два', [$channel]), $day->setTime(10, 0));

        try {
            $this->service()->schedule($context, null, $this->draft('Три', [$channel]), $day->setTime(11, 0));
            self::fail('the third post of the day must be refused');
        } catch (PostException $e) {
            self::assertStringContainsString('2 из 2', $e->getMessage());
            self::assertStringContainsString('в сутки', $e->getMessage());
            self::assertArrayHasKey($channel->publicId, $e->channelProblems);
        }

        // Another day is fine; a cancelled post frees its place; moving a post within its own day does not count it twice.
        $this->service()->schedule($context, null, $this->draft('Другой день', [$channel]), $day->modify('+1 day')->setTime(9, 0));
        $this->service()->reschedule($context, $first, $day->setTime(12, 0));
        $this->service()->cancel($context, $first);
        $this->service()->schedule($context, null, $this->draft('Три', [$channel]), $day->setTime(11, 0));
    }

    public function testMovingAPostIntoAFullDayIsRefused(): void
    {
        [$context, $channel] = $this->workspace();
        $this->fake->dailyLimit = 1;
        $day = $this->in('+2 days');
        $this->service()->schedule($context, null, $this->draft('Занято', [$channel]), $day->setTime(9, 0));
        $other = $this->service()->schedule($context, null, $this->draft('Двигаем', [$channel]), $day->modify('+1 day')->setTime(9, 0));

        $this->expectException(PostException::class);
        $this->service()->reschedule($context, $other, $day->setTime(15, 0));
    }

    public function testNetworksWithoutALimitAreNotCounted(): void
    {
        [$context, $channel] = $this->workspace();
        $day = $this->in('+2 days');
        for ($i = 0; $i < 4; ++$i) {
            $this->service()->schedule($context, null, $this->draft('Пост ' . $i, [$channel]), $day->setTime(9 + $i, 0));
        }
        self::assertSame(4, (int) $this->db->select('SELECT COUNT(*) AS n FROM publications')[0]['n']);
    }
}
