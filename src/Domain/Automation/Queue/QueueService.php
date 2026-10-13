<?php

declare(strict_types=1);

namespace App\Domain\Automation\Queue;

use App\Domain\Audit\AuditLog;
use App\Domain\Automation\AutomationException;
use App\Domain\Billing\Entitlements;
use App\Domain\Billing\PlanLimitException;
use App\Domain\Channel\ChannelRepository;
use App\Domain\Post\Post;
use App\Domain\Post\PostException;
use App\Domain\Post\PostRepository;
use App\Domain\Post\PostService;
use App\Domain\Post\PostStatus;
use App\Domain\Post\PublishingContexts;
use App\Domain\Workspace\Permissions;
use App\Domain\Workspace\WorkspaceContext;
use App\Kernel\Database\Connection;
use App\Support\Clock;
use DateTimeImmutable;

/**
 * Queues with slots (master plan, stage 13). A queue is an ordered list of posts and a weekly schedule; the posts take the next free
 * slots in order. The queue owns the time of its posts: whenever the list changes (a post joins, leaves, moves, a queue resumes) the
 * times are worked out again from the top, and a post whose time is already right is left alone.
 *
 * A paused queue holds its posts as drafts; resuming places them again in the same order. A post that cannot be placed (a channel
 * is broken, a plan limit is hit) keeps its place in the list with a note and does not use up a slot.
 */
final class QueueService
{
    public const MAX_QUEUES = 20;
    public const MAX_SLOTS = 70;
    public const MAX_ENTRIES = 200;
    /** A post is never placed closer than this to now (the scheduler needs a moment to pick it up), and one planned sooner is left alone. */
    private const LOCK_SECONDS = 120;

    public function __construct(
        private readonly Connection $db,
        private readonly QueueRepository $queues,
        private readonly PostRepository $posts,
        private readonly PostService $service,
        private readonly ChannelRepository $channels,
        private readonly Entitlements $entitlements,
        private readonly Permissions $permissions,
        private readonly PublishingContexts $contexts,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
    ) {
    }

    // ---- queues ----------------------------------------------------------------------------------------------------

    /**
     * @param list<array{weekday: int, time: string}> $slots
     * @param list<string> $channelPublicIds
     * @throws AutomationException
     */
    public function create(WorkspaceContext $context, string $name, array $slots, array $channelPublicIds): PostQueue
    {
        $this->authorize($context);
        $this->assertFeature($context);
        if ($this->queues->count($context) >= self::MAX_QUEUES) {
            throw new AutomationException(sprintf('Очередей может быть не больше %d. Удалите ненужную.', self::MAX_QUEUES));
        }
        [$name, $slots, $channelIds] = $this->check($context, $name, $slots, $channelPublicIds);
        $queue = $this->queues->create($context, $name, $slots, $channelIds);
        $this->audit->record('queue.created', $context->userId, 'queue', $queue->publicId, ['name' => $queue->name], $context->workspaceId);

        return $queue;
    }

    /**
     * @param list<array{weekday: int, time: string}> $slots
     * @param list<string> $channelPublicIds
     * @throws AutomationException
     */
    public function update(WorkspaceContext $context, PostQueue $queue, string $name, array $slots, array $channelPublicIds): void
    {
        $this->authorize($context);
        [$name, $slots, $channelIds] = $this->check($context, $name, $slots, $channelPublicIds);
        $this->queues->update($context, $queue, $name, $slots, $channelIds);
        $this->audit->record('queue.updated', $context->userId, 'queue', $queue->publicId, ['name' => $name], $context->workspaceId);
        $fresh = $this->queues->findById($context, $queue->id);
        if ($fresh !== null) {
            $this->place($context, $fresh);
        }
    }

    /**
     * Delete a queue. Its posts stay where they are planned; the queue just stops moving them.
     */
    public function delete(WorkspaceContext $context, PostQueue $queue): void
    {
        $this->authorize($context);
        $this->queues->delete($context, $queue);
        $this->audit->record('queue.deleted', $context->userId, 'queue', $queue->publicId, ['name' => $queue->name], $context->workspaceId);
    }

    public function pause(WorkspaceContext $context, PostQueue $queue): void
    {
        $this->authorize($context);
        $this->queues->setPaused($context, $queue, true);
        $fresh = $this->queues->findById($context, $queue->id);
        if ($fresh !== null) {
            $this->place($context, $fresh);
        }
        $this->audit->record('queue.paused', $context->userId, 'queue', $queue->publicId, [], $context->workspaceId);
    }

    public function resume(WorkspaceContext $context, PostQueue $queue): void
    {
        $this->authorize($context);
        $this->queues->setPaused($context, $queue, false);
        $fresh = $this->queues->findById($context, $queue->id);
        if ($fresh !== null) {
            $this->place($context, $fresh);
        }
        $this->audit->record('queue.resumed', $context->userId, 'queue', $queue->publicId, [], $context->workspaceId);
    }

    // ---- posts in a queue ------------------------------------------------------------------------------------------

    /**
     * Put a post at the end of the queue; it takes the next free slot.
     *
     * @throws AutomationException
     */
    public function enqueue(WorkspaceContext $context, Post $post, PostQueue $queue, string $source = 'manual'): Post
    {
        $this->authorize($context);
        $this->assertFeature($context);
        $post = $this->posts->findById($context, $post->id) ?? $post; // the caller's copy may be older than the database
        if (!$this->service->canSee($context, $post) || !in_array($post->status, [PostStatus::Draft, PostStatus::Scheduled], true)) {
            throw new AutomationException('В очередь можно поставить только черновик или запланированный пост.');
        }
        if ($this->queues->entryOfPost($context, $post->id) !== null) {
            throw new AutomationException('Этот пост уже стоит в очереди.');
        }
        if (count($this->queues->entries($context, $queue)) >= self::MAX_ENTRIES) {
            throw new AutomationException(sprintf('В очереди уже %d постов. Дождитесь, пока часть выйдет.', self::MAX_ENTRIES));
        }
        $this->assertChannelsFit($context, $post, $queue);
        $this->queues->addEntry($context, $queue, $post->id, $source);
        $this->place($context, $queue);
        $this->audit->record('queue.post_added', $context->userId, 'queue', $queue->publicId, ['post' => $post->publicId], $context->workspaceId);

        return $this->posts->findById($context, $post->id) ?? $post;
    }

    /**
     * Take a post out of the queue and back to drafts.
     *
     * @throws AutomationException
     */
    public function dequeue(WorkspaceContext $context, PostQueue $queue, Post $post): void
    {
        $this->authorize($context);
        $this->queues->removeEntry($context, $queue, $post->id);
        $post = $this->posts->findById($context, $post->id) ?? $post;
        try {
            $this->service->unschedule($context, $post);
        } catch (PostException) {
            // The post is already on its way out; it only leaves the list.
        }
        $this->place($context, $queue);
        $this->audit->record('queue.post_removed', $context->userId, 'queue', $queue->publicId, ['post' => $post->publicId], $context->workspaceId);
    }

    /**
     * Move a post one place up (`-1`) or down (`+1`); the times follow.
     */
    public function move(WorkspaceContext $context, PostQueue $queue, Post $post, int $delta): void
    {
        $this->authorize($context);
        $ids = array_map(static fn (QueueEntry $e): int => $e->postId, $this->queues->entries($context, $queue));
        $index = array_search($post->id, $ids, true);
        $target = $index === false ? false : $index + ($delta < 0 ? -1 : 1);
        if ($index === false || $target < 0 || $target >= count($ids)) {
            return;
        }
        [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];
        $this->queues->reorder($context, $queue, $ids);
        $this->place($context, $queue);
        $this->audit->record('queue.reordered', $context->userId, 'queue', $queue->publicId, ['post' => $post->publicId], $context->workspaceId);
    }

    /**
     * Work out the times of all posts of the queue from the top. Safe to repeat; a post whose time is right is not touched.
     *
     * @return int posts that were planned or moved
     */
    public function place(WorkspaceContext $context, PostQueue $queue): int
    {
        $entries = [];
        foreach ($this->queues->entries($context, $queue) as $entry) {
            $post = $this->posts->findById($context, $entry->postId);
            if ($post === null || !in_array($post->status, [PostStatus::Draft, PostStatus::Scheduled], true)) {
                $this->queues->removeEntry($context, $queue, $entry->postId);
                continue;
            }
            $entries[] = [$entry, $post];
        }
        $changed = 0;
        if ($queue->paused) {
            foreach ($entries as [$entry, $post]) {
                try {
                    $post = $this->service->unschedule($context, $post);
                    $this->queues->setNote($context, $entry, 'Очередь на паузе.');
                } catch (PostException $e) {
                    $this->queues->setNote($context, $entry, $e->getMessage());
                }
            }

            return 0;
        }
        $locked = $this->clock->now()->modify('+' . self::LOCK_SECONDS . ' seconds');
        $slots = SlotCalculator::next($queue->slots, $context->timezone, $locked, count($entries));
        $next = 0;
        foreach ($entries as [$entry, $post]) {
            // A post that goes out within a couple of minutes is not touched any more; it does not take a slot of the list either.
            if ($post->status === PostStatus::Scheduled && $post->scheduledAt !== null && $post->scheduledAt <= $locked) {
                continue;
            }
            $at = $slots[$next] ?? null;
            if ($at === null) {
                $this->queues->setNote($context, $entry, 'В расписании нет свободных слотов. Добавьте дни и время.');
                continue;
            }
            if ($post->status === PostStatus::Scheduled && $post->scheduledAt !== null && $post->scheduledAt->getTimestamp() === $at->getTimestamp()) {
                $this->queues->setNote($context, $entry, null);
                ++$next;
                continue;
            }
            try {
                $this->service->planExisting($context, $post, $at);
                $this->queues->setNote($context, $entry, null);
                ++$next;
                ++$changed;
            } catch (PostException $e) {
                // The post keeps its place, but takes no slot: the next one goes there.
                $this->queues->setNote($context, $entry, $e->getMessage());
            }
        }

        return $changed;
    }

    /**
     * The next free slots of a queue (for the page): after the posts that already wait.
     *
     * @return list<DateTimeImmutable>
     */
    public function upcomingSlots(WorkspaceContext $context, PostQueue $queue, int $count): array
    {
        return SlotCalculator::next($queue->slots, $context->timezone, $this->clock->now(), $count);
    }

    /**
     * Every minute: posts that went out leave their queues, queues with a waiting draft are placed again. Cheap when nothing changed.
     */
    public function tick(): void
    {
        $rows = $this->db->select(
            'SELECT DISTINCT q.id, q.workspace_id FROM queues q JOIN queue_entries e ON e.queue_id = q.id JOIN posts p ON p.id = e.post_id
             WHERE q.paused = 0 AND (p.status NOT IN (\'draft\', \'scheduled\') OR p.status = \'draft\' OR p.scheduled_at < ?)',
            [\App\Support\DbTime::format($this->clock->now())],
        );
        foreach ($rows as $row) {
            $context = $this->contexts->forWorkspace((int) $row['workspace_id']);
            $queue = $context === null ? null : $this->queues->findById($context, (int) $row['id']);
            if ($context !== null && $queue !== null) {
                $this->place($context, $queue);
            }
        }
    }

    // ---- internals -------------------------------------------------------------------------------------------------

    /**
     * @throws AutomationException
     */
    private function authorize(WorkspaceContext $context): void
    {
        if (!$this->permissions->allows($context->role, 'posts.publish')) {
            throw new AutomationException('Очередями управляют редакторы и администраторы.', forbidden: true);
        }
    }

    /**
     * @throws AutomationException
     */
    private function assertFeature(WorkspaceContext $context): void
    {
        try {
            $this->entitlements->assertFeature($context->workspaceId, 'slots', 'Очередь постов со слотами');
        } catch (PlanLimitException $e) {
            throw new AutomationException($e->getMessage(), planLimit: true);
        }
    }

    /**
     * @param list<array{weekday: int, time: string}> $slots
     * @param list<string> $channelPublicIds
     * @return array{string, list<array{weekday: int, time: string}>, list<int>}
     * @throws AutomationException
     */
    private function check(WorkspaceContext $context, string $name, array $slots, array $channelPublicIds): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw new AutomationException('Назовите очередь: от 1 до 100 символов.');
        }
        $clean = [];
        foreach ($slots as $slot) {
            if ($slot['weekday'] < 1 || $slot['weekday'] > 7 || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $slot['time']) !== 1) {
                throw new AutomationException('Проверьте дни и время слотов.');
            }
            $clean[$slot['weekday'] . ' ' . $slot['time']] = $slot;
        }
        if ($clean === []) {
            throw new AutomationException('Добавьте хотя бы один слот: день недели и время.');
        }
        if (count($clean) > self::MAX_SLOTS) {
            throw new AutomationException(sprintf('Слотов может быть не больше %d.', self::MAX_SLOTS));
        }
        $allowed = $this->service->allowedChannels($context);
        $ids = [];
        foreach ($channelPublicIds as $publicId) {
            $channel = $this->channels->find($context, $publicId);
            if ($channel === null || ($allowed !== null && !in_array($channel->id, $allowed, true))) {
                throw new AutomationException('Один из выбранных каналов не найден или недоступен вам.');
            }
            $ids[$channel->id] = $channel->id;
        }
        if ($ids === []) {
            throw new AutomationException('Выберите хотя бы один канал для очереди.');
        }

        return [$name, array_values($clean), array_values($ids)];
    }

    /**
     * @throws AutomationException
     */
    private function assertChannelsFit(WorkspaceContext $context, Post $post, PostQueue $queue): void
    {
        foreach ($this->posts->variants($context, $post) as $variant) {
            if ($variant->channelId === null || !in_array($variant->channelId, $queue->channelIds, true)) {
                throw new AutomationException('Канал «' . $variant->channelName . '» не входит в эту очередь. Выберите другую очередь или добавьте канал в её настройках.');
            }
        }
    }
}
