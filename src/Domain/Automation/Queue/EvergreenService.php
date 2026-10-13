<?php

declare(strict_types=1);

namespace App\Domain\Automation\Queue;

use App\Domain\Audit\AuditLog;
use App\Domain\Automation\AutomationException;
use App\Domain\Billing\Entitlements;
use App\Domain\Billing\PlanLimitException;
use App\Domain\Post\Post;
use App\Domain\Post\PostException;
use App\Domain\Post\PostRepository;
use App\Domain\Post\PostService;
use App\Domain\Post\PublishingContexts;
use App\Domain\Workspace\Permissions;
use App\Domain\Workspace\WorkspaceContext;
use App\Kernel\Database\Connection;
use App\Support\Clock;

/**
 * The evergreen pool: posts that are worth repeating, published round and round into the slots of a queue.
 *
 * The posts of the pool stay drafts (the "library"); each round a copy is made and put at the end of the queue, so the history of
 * every publication stays separate. A post is chosen when its last use is at least `minIntervalDays` before the slot it would get,
 * least recently used first, so the pool goes in a circle and no post repeats too soon. The queue entry is tagged with the pool
 * (`evergreen:<pool id>`) so the pool knows how many of its copies are still waiting.
 */
final class EvergreenService
{
    public const MAX_POOLS = 10;
    public const MAX_POSTS = 200;

    public function __construct(
        private readonly Connection $db,
        private readonly EvergreenRepository $pools,
        private readonly QueueRepository $queues,
        private readonly QueueService $queueService,
        private readonly PostRepository $posts,
        private readonly PostService $service,
        private readonly Entitlements $entitlements,
        private readonly Permissions $permissions,
        private readonly PublishingContexts $contexts,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @throws AutomationException
     */
    public function create(WorkspaceContext $context, PostQueue $queue, string $name, int $minIntervalDays, int $keepAhead): EvergreenPool
    {
        $this->authorize($context);
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw new AutomationException('Назовите пул: от 1 до 100 символов.');
        }
        if ($minIntervalDays < 1 || $minIntervalDays > 365) {
            throw new AutomationException('Минимальный интервал повтора — от 1 до 365 дней.');
        }
        if ($keepAhead < 1 || $keepAhead > 10) {
            throw new AutomationException('Держать в очереди можно от 1 до 10 постов.');
        }
        if ($this->pools->count($context) >= self::MAX_POOLS) {
            throw new AutomationException(sprintf('Пулов может быть не больше %d.', self::MAX_POOLS));
        }
        $pool = $this->pools->create($context, $queue->id, $name, $minIntervalDays, $keepAhead);
        $this->audit->record('evergreen.created', $context->userId, 'evergreen_pool', $pool->publicId, ['name' => $name], $context->workspaceId);

        return $pool;
    }

    public function delete(WorkspaceContext $context, EvergreenPool $pool): void
    {
        $this->authorize($context);
        $this->pools->delete($context, $pool);
        $this->audit->record('evergreen.deleted', $context->userId, 'evergreen_pool', $pool->publicId, ['name' => $pool->name], $context->workspaceId);
    }

    public function setEnabled(WorkspaceContext $context, EvergreenPool $pool, bool $enabled): void
    {
        $this->authorize($context);
        $this->pools->setEnabled($context, $pool, $enabled);
        $this->audit->record($enabled ? 'evergreen.enabled' : 'evergreen.disabled', $context->userId, 'evergreen_pool', $pool->publicId, [], $context->workspaceId);
    }

    /**
     * @throws AutomationException
     */
    public function addPost(WorkspaceContext $context, EvergreenPool $pool, Post $post): void
    {
        $this->authorize($context);
        if (!$this->service->canSee($context, $post)) {
            throw new AutomationException('Пост не найден.');
        }
        if (count($this->pools->posts($context, $pool)) >= self::MAX_POSTS) {
            throw new AutomationException(sprintf('В пуле может быть не больше %d постов.', self::MAX_POSTS));
        }
        if (!$this->pools->addPost($context, $pool, $post->id)) {
            throw new AutomationException('Этот пост уже в пуле.');
        }
        $this->audit->record('evergreen.post_added', $context->userId, 'evergreen_pool', $pool->publicId, ['post' => $post->publicId], $context->workspaceId);
    }

    public function removePost(WorkspaceContext $context, EvergreenPool $pool, Post $post): void
    {
        $this->authorize($context);
        $this->pools->removePost($context, $pool, $post->id);
    }

    /**
     * Top the queue up with copies from the pool until it holds `keepAhead` of them.
     *
     * @return int copies added
     */
    public function fill(WorkspaceContext $context, EvergreenPool $pool): int
    {
        $queue = $this->queues->findById($context, $pool->queueId);
        if ($queue === null || $queue->paused || !$pool->enabled) {
            return 0;
        }
        $tag = 'evergreen:' . $pool->publicId;
        $added = 0;
        for ($guard = 0; $guard < $pool->keepAhead; ++$guard) {
            $entries = $this->queues->entries($context, $queue);
            $waiting = count(array_filter($entries, static fn (QueueEntry $e): bool => $e->source === $tag));
            if ($waiting >= $pool->keepAhead || count($entries) >= QueueService::MAX_ENTRIES) {
                break;
            }
            $target = $this->queueService->upcomingSlots($context, $queue, count($entries) + 1);
            $at = $target === [] ? null : $target[array_key_last($target)];
            $chosen = $at === null ? null : $this->choose($context, $pool, $at);
            if ($chosen === null) {
                break;
            }
            try {
                $copy = $this->service->duplicate($context, $chosen);
                $copy = $this->queueService->enqueue($context, $copy, $queue, $tag);
            } catch (PostException | AutomationException) {
                break; // a channel of the post is gone or the queue does not take it: nothing more to do this round
            }
            $this->pools->markUsed($context, $pool, $chosen->id, $copy->scheduledAt ?? $at);
            ++$added;
        }

        return $added;
    }

    /**
     * Every minute: fill the pools that are on.
     */
    public function tick(): void
    {
        foreach ($this->db->table('evergreen_pools')->where('enabled', '=', 1)->limit(500)->get() as $row) {
            $context = $this->contexts->forWorkspace((int) $row['workspace_id']);
            $pool = $context === null ? null : $this->pools->findById($context, (int) $row['id']);
            if ($context !== null && $pool !== null) {
                $this->fill($context, $pool);
            }
        }
    }

    /**
     * The least recently used post whose last use is far enough before `$at`.
     */
    private function choose(WorkspaceContext $context, EvergreenPool $pool, \DateTimeImmutable $at): ?Post
    {
        foreach ($this->pools->posts($context, $pool) as $row) {
            $last = $row['last_used_at'];
            if ($last !== null && $last->modify('+' . $pool->minIntervalDays . ' days') > $at) {
                continue;
            }
            $post = $this->posts->findById($context, $row['post_id']);
            if ($post !== null) {
                return $post;
            }
        }

        return null;
    }

    /**
     * @throws AutomationException
     */
    private function authorize(WorkspaceContext $context): void
    {
        if (!$this->permissions->allows($context->role, 'posts.publish')) {
            throw new AutomationException('Пулом управляют редакторы и администраторы.', forbidden: true);
        }
        try {
            $this->entitlements->assertFeature($context->workspaceId, 'slots', 'Вечнозелёный пул');
        } catch (PlanLimitException $e) {
            throw new AutomationException($e->getMessage(), planLimit: true);
        }
    }
}
