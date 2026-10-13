<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\Automation\Queue\PostQueue;
use App\Domain\Automation\Queue\QueueService;
use App\Domain\Channel\Channel;
use App\Domain\Post\Post;
use App\Domain\Workspace\Workspace;
use App\Domain\Workspace\WorkspaceContext;
use App\Kernel\Queue\Worker;

/**
 * Base class for the stage 13 tests: a workspace on the Pro plan with a channel of the test network, the queue service, and a way
 * to run what the worker would run.
 */
abstract class AutomationTestCase extends PostTestCase
{
    protected Workspace $workspace;
    protected \App\Domain\User\User $owner;
    protected WorkspaceContext $context;
    protected Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->owner, $this->workspace] = $this->ownerWithWorkspace();
        $this->givePlan($this->workspace, 'pro');
        $this->context = $this->contextFor($this->workspace, $this->owner);
        $this->channel = $this->fakeChannel($this->workspace, $this->owner);
    }

    protected function queues(): QueueService
    {
        return $this->app->container()->get(QueueService::class);
    }

    /**
     * Monday to Friday at 10:00 and 15:00 (workspace time) for the test channel.
     */
    protected function weekdayQueue(?Channel $channel = null, string $name = 'Будни'): PostQueue
    {
        $slots = [];
        foreach ([1, 2, 3, 4, 5] as $day) {
            foreach (['10:00', '15:00'] as $time) {
                $slots[] = ['weekday' => $day, 'time' => $time];
            }
        }

        return $this->queues()->create($this->context, $name, $slots, [($channel ?? $this->channel)->publicId]);
    }

    protected function draftPost(string $text = 'Пост', ?Channel $channel = null): Post
    {
        return $this->service()->saveDraft($this->context, null, $this->draft($text, [$channel ?? $this->channel]));
    }

    protected function fresh(Post $post): Post
    {
        return $this->posts()->findById($this->context, $post->id) ?? throw new \LogicException('The post is gone.');
    }

    /**
     * Local "D H:i" of a post's planned time in the workspace's zone.
     */
    protected function plannedAt(Post $post): string
    {
        $at = $this->fresh($post)->scheduledAt;

        return $at === null ? 'none' : $at->setTimezone(new \DateTimeZone($this->context->timezone))->format('D Y-m-d H:i');
    }

    /**
     * Run every job of a queue that is available now.
     */
    protected function runJobs(string $queue = 'default'): int
    {
        $worker = $this->app->container()->get(Worker::class);
        $count = 0;
        while ($worker->runNext($queue, 'test')) {
            ++$count;
        }

        return $count;
    }
}
