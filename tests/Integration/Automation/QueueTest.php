<?php

declare(strict_types=1);

namespace App\Tests\Integration\Automation;

use App\Domain\Automation\AutomationException;
use App\Domain\Automation\Queue\EvergreenRepository;
use App\Domain\Automation\Queue\EvergreenService;
use App\Domain\Automation\Queue\QueueRepository;
use App\Domain\Automation\Queue\QueueService;
use App\Domain\Post\PostStatus;
use App\Domain\Workspace\Role;
use App\Tests\Support\AutomationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(QueueService::class)]
#[CoversClass(QueueRepository::class)]
#[CoversClass(EvergreenService::class)]
#[CoversClass(EvergreenRepository::class)]
final class QueueTest extends AutomationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->clock->set('2026-10-12 06:00:00'); // Monday 09:00 in Moscow
    }

    public function testPostsTakeTheNextFreeSlotsInOrder(): void
    {
        $queue = $this->weekdayQueue();
        [$a, $b, $c] = [$this->draftPost('A'), $this->draftPost('B'), $this->draftPost('C')];
        foreach ([$a, $b, $c] as $post) {
            $this->queues()->enqueue($this->context, $post, $queue);
        }
        self::assertSame('Mon 2026-10-12 10:00', $this->plannedAt($a));
        self::assertSame('Mon 2026-10-12 15:00', $this->plannedAt($b));
        self::assertSame('Tue 2026-10-13 10:00', $this->plannedAt($c));
        self::assertSame(PostStatus::Scheduled, $this->fresh($a)->status);
        // Joining twice, or with a channel the queue does not publish to, is refused.
        $this->expectExceptionObject(new AutomationException('Этот пост уже стоит в очереди.'));
        $this->queues()->enqueue($this->context, $a, $queue);
    }

    public function testReorderingMovesThePostsBetweenTheSameSlots(): void
    {
        $queue = $this->weekdayQueue();
        [$a, $b, $c] = [$this->draftPost('A'), $this->draftPost('B'), $this->draftPost('C')];
        foreach ([$a, $b, $c] as $post) {
            $this->queues()->enqueue($this->context, $post, $queue);
        }
        $this->queues()->move($this->context, $queue, $c, -1);
        self::assertSame('Mon 2026-10-12 10:00', $this->plannedAt($a));
        self::assertSame('Mon 2026-10-12 15:00', $this->plannedAt($c));
        self::assertSame('Tue 2026-10-13 10:00', $this->plannedAt($b));
        // Moving past the ends changes nothing.
        $this->queues()->move($this->context, $queue, $a, -1);
        self::assertSame('Mon 2026-10-12 10:00', $this->plannedAt($a));
        // Taking a post out closes the gap.
        $this->queues()->dequeue($this->context, $queue, $c);
        self::assertSame(PostStatus::Draft, $this->fresh($c)->status);
        self::assertSame('Mon 2026-10-12 15:00', $this->plannedAt($b));
    }

    public function testAPausedQueueHoldsItsPostsAsDraftsAndResumingPlacesThemAgain(): void
    {
        $queue = $this->weekdayQueue();
        [$a, $b] = [$this->draftPost('A'), $this->draftPost('B')];
        $this->queues()->enqueue($this->context, $a, $queue);
        $this->queues()->enqueue($this->context, $b, $queue);
        $this->queues()->pause($this->context, $queue);
        self::assertSame([PostStatus::Draft, PostStatus::Draft], [$this->fresh($a)->status, $this->fresh($b)->status]);
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS n FROM publications WHERE status = ?', ['queued'])[0]['n']);

        $paused = $this->app->container()->get(QueueRepository::class)->findById($this->context, $queue->id);
        self::assertTrue($paused?->paused);
        $this->queues()->resume($this->context, $paused ?? $queue);
        self::assertSame('Mon 2026-10-12 10:00', $this->plannedAt($a));
        self::assertSame('Mon 2026-10-12 15:00', $this->plannedAt($b));
    }

    public function testPublishedPostsLeaveTheQueueAndTheRestKeepTheirTimes(): void
    {
        $queue = $this->weekdayQueue();
        [$a, $b] = [$this->draftPost('A'), $this->draftPost('B')];
        $this->queues()->enqueue($this->context, $a, $queue);
        $this->queues()->enqueue($this->context, $b, $queue);

        $this->at('2026-10-12 07:00:05'); // 10:00:05 Moscow: the first slot has passed
        $this->drain();
        self::assertSame(PostStatus::Published, $this->fresh($a)->status);
        $this->queues()->tick();
        $entries = $this->app->container()->get(QueueRepository::class)->entries($this->context, $queue);
        self::assertCount(1, $entries);
        self::assertSame($b->id, $entries[0]->postId);
        self::assertSame('Mon 2026-10-12 15:00', $this->plannedAt($b));
    }

    public function testAPostThatGoesOutSoonIsNotMovedAndTakesNoSlot(): void
    {
        $queue = $this->weekdayQueue();
        [$a, $b] = [$this->draftPost('A'), $this->draftPost('B')];
        $this->queues()->enqueue($this->context, $a, $queue);
        $this->queues()->enqueue($this->context, $b, $queue);
        $this->at('2026-10-12 06:59:30'); // 30 seconds before the 10:00 slot of the first post
        $this->queues()->place($this->context, $queue);
        self::assertSame('Mon 2026-10-12 10:00', $this->plannedAt($a));
        self::assertSame('Mon 2026-10-12 15:00', $this->plannedAt($b));
    }

    public function testQueueNeedsThePlanTheRoleAndTheRightChannels(): void
    {
        $free = $this->createUser('free@example.com');
        $freeWorkspace = $this->app->container()->get(\App\Domain\Workspace\WorkspaceService::class)->createPersonal($free);
        $this->givePlan($freeWorkspace, 'free');
        try {
            $this->queues()->create($this->contextFor($freeWorkspace, $free), 'Очередь', [['weekday' => 1, 'time' => '10:00']], []);
            self::fail('the Free plan has no queues');
        } catch (AutomationException $e) {
            self::assertTrue($e->planLimit);
        }

        $author = $this->contextFor($this->workspace, $this->memberOf($this->workspace, 'author@example.com', Role::Author));
        try {
            $this->queues()->create($author, 'Очередь', [['weekday' => 1, 'time' => '10:00']], [$this->channel->publicId]);
            self::fail('an author may not manage queues');
        } catch (AutomationException $e) {
            self::assertTrue($e->forbidden);
        }

        foreach ([
            [[], [$this->channel->publicId]],
            [[['weekday' => 9, 'time' => '10:00']], [$this->channel->publicId]],
            [[['weekday' => 1, 'time' => '25:00']], [$this->channel->publicId]],
            [[['weekday' => 1, 'time' => '10:00']], []],
            [[['weekday' => 1, 'time' => '10:00']], ['01HZZZZZZZZZZZZZZZZZZZZZZZ']],
        ] as $i => [$slots, $channels]) {
            try {
                $this->queues()->create($this->context, 'Плохая', $slots, $channels);
                self::fail('case ' . $i . ' must be refused');
            } catch (AutomationException $e) {
                self::assertFalse($e->forbidden);
            }
        }

        // A channel of another workspace is "not found", not "forbidden to see".
        [$other, $otherWorkspace] = $this->ownerWithWorkspace('other@example.com');
        $foreign = $this->fakeChannel($otherWorkspace, $other, 'fake-other');
        $this->expectException(AutomationException::class);
        $this->queues()->create($this->context, 'Чужой', [['weekday' => 1, 'time' => '10:00']], [$foreign->publicId]);
    }

    public function testAPostForAChannelOutsideTheQueueIsRefused(): void
    {
        $queue = $this->weekdayQueue();
        $second = $this->fakeChannel($this->workspace, $this->owner, 'fake-2', 'Второй');
        $this->expectException(AutomationException::class);
        $this->expectExceptionMessage('не входит в эту очередь');
        $this->queues()->enqueue($this->context, $this->draftPost('Чужой канал', $second), $queue);
    }

    public function testAPostThatCannotBePlacedKeepsItsPlaceAndTakesNoSlot(): void
    {
        $queue = $this->weekdayQueue();
        [$bad, $good] = [$this->draftPost('Плохой'), $this->draftPost('Хороший')];
        $this->queues()->enqueue($this->context, $bad, $queue);
        $this->queues()->enqueue($this->context, $good, $queue);
        // The first post is emptied behind the queue's back: it can not be planned any more.
        $this->db->table('posts')->where('id', '=', $bad->id)->update(['base_text' => '', 'status' => 'draft', 'scheduled_at' => null]);
        $this->db->table('publications')->where('post_id', '=', $bad->id)->delete();
        $this->queues()->place($this->context, $queue);
        $entries = $this->app->container()->get(QueueRepository::class)->entries($this->context, $queue);
        self::assertNotNull($entries[0]->note, 'the reason is kept next to the post');
        self::assertSame('Mon 2026-10-12 10:00', $this->plannedAt($good), 'the good post takes the first slot');
    }

    public function testEvergreenPoolCirclesThroughItsPostsWithTheMinimumInterval(): void
    {
        $slots = array_map(static fn (int $d): array => ['weekday' => $d, 'time' => '12:00'], [1, 2, 3, 4, 5, 6, 7]);
        $queue = $this->queues()->create($this->context, 'Каждый день', $slots, [$this->channel->publicId]);
        $pools = $this->app->container()->get(EvergreenService::class);
        $pool = $pools->create($this->context, $queue, 'Полезное', 2, 4);
        [$a, $b] = [$this->draftPost('A'), $this->draftPost('B')];
        $pools->addPost($this->context, $pool, $a);
        $pools->addPost($this->context, $pool, $b);
        try {
            $pools->addPost($this->context, $pool, $a);
            self::fail('a post is in a pool once');
        } catch (AutomationException) {
            self::assertTrue(true);
        }

        // Four copies are wanted, but with a two-day interval and two posts: A, B, A, B on days 1..4.
        self::assertSame(4, $pools->fill($this->context, $pool));
        $copies = $this->db->select('SELECT p.base_text, p.scheduled_at FROM queue_entries e JOIN posts p ON p.id = e.post_id WHERE e.queue_id = ? ORDER BY e.position', [$queue->id]);
        self::assertSame(['A', 'B', 'A', 'B'], array_column($copies, 'base_text'));
        self::assertSame(0, $pools->fill($this->context, $pool), 'the pool is full: nothing more is added');
        $days = array_map(static fn (array $r): string => substr((string) $r['scheduled_at'], 0, 10), $copies);
        self::assertSame(['2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15'], $days);
    }

    public function testEvergreenPostsDoNotComeRoundBeforeTheirInterval(): void
    {
        $slots = array_map(static fn (int $d): array => ['weekday' => $d, 'time' => '12:00'], [1, 2, 3, 4, 5, 6, 7]);
        $queue = $this->queues()->create($this->context, 'Каждый день', $slots, [$this->channel->publicId]);
        $pools = $this->app->container()->get(EvergreenService::class);
        $pool = $pools->create($this->context, $queue, 'Редкое', 10, 5);
        $only = $this->draftPost('Один');
        $pools->addPost($this->context, $pool, $only);
        // One post and a ten-day interval: it goes out once, the pool then waits.
        self::assertSame(1, $pools->fill($this->context, $pool));
        self::assertSame(0, $pools->fill($this->context, $pool));
        self::assertSame(1, (int) $this->db->select('SELECT uses FROM evergreen_posts')[0]['uses']);
    }
}
