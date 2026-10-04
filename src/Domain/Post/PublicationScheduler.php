<?php

declare(strict_types=1);

namespace App\Domain\Post;

use App\Domain\Workspace\WorkspaceRepository;
use App\Domain\Notification\Notifier;
use App\Kernel\Queue\Queue;
use App\Support\Clock;
use Psr\Log\LoggerInterface;

/**
 * What the scheduler does every minute for publishing:
 *
 * - `enqueueDue()`: for every queued publication that falls due in the next 90 seconds, put a `PublishJob` in the queue, delayed until
 *   the planned moment, so the post goes out at its time and not at the next tick (this is what keeps the delay far below a minute);
 *   a job that went missing is created again;
 * - `reapStuck()`: a publication that has been "sending" for 15 minutes belongs to a dead worker; it becomes `unknown` (never retried
 *   by itself) and the owner is told;
 * - `enqueueDeletions()`: posts whose "delete after" time has come.
 *
 * Creating a job twice is harmless (the claim lets one run win), so none of this needs a lock.
 */
final class PublicationScheduler
{
    public const HORIZON_SECONDS = 90;
    public const STALE_SECONDS = 300;
    public const STUCK_SECONDS = 900;
    private const BATCH = 500;

    public function __construct(
        private readonly PublicationSystem $system,
        private readonly Queue $queue,
        private readonly Clock $clock,
        private readonly WorkspaceRepository $workspaces,
        private readonly Notifier $notifier,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * The scheduled task: all three jobs of the minute.
     */
    public function tick(): void
    {
        $this->reapStuck();
        $this->enqueueDue();
        $this->enqueueDeletions();
    }

    /**
     * @return int number of jobs created
     */
    public function enqueueDue(): int
    {
        $due = $this->system->dueForEnqueue(self::HORIZON_SECONDS, self::STALE_SECONDS, self::BATCH);
        $now = (float) $this->clock->now()->format('U.u');
        $ids = [];
        foreach ($due as $item) {
            $delay = (int) max(0, ceil((float) $item['run_at']->format('U.u') - $now));
            $this->queue->dispatch(new PublishJob($item['id']), $delay, PublishJob::QUEUE);
            $ids[] = $item['id'];
        }
        $this->system->markEnqueued($ids);

        return count($ids);
    }

    /**
     * @return int number of publications given up as unknown
     */
    public function reapStuck(): int
    {
        $count = 0;
        foreach ($this->system->stuckSending(self::STUCK_SECONDS) as $id) {
            $publication = $this->system->find($id);
            if ($publication === null) {
                continue;
            }
            $message = 'Отправка прервалась, и мы не знаем, вышел ли пост. Проверьте канал и отметьте результат на странице поста.';
            if (!$this->system->markUnknown($publication, 'worker_lost', $message, 'publication stayed in sending for ' . self::STUCK_SECONDS . ' seconds')) {
                continue;
            }
            ++$count;
            $this->system->recordAttempt($publication, 'unknown', 'unknown_outcome', $message, 'worker lost', $publication->startedAt ?? $this->clock->now());
            $this->system->syncPostStatus($publication->postId);
            $loaded = $this->system->load($publication);
            $workspace = $loaded === null ? null : $this->workspaces->findById($loaded['post']->workspaceId);
            if ($loaded !== null && $workspace !== null) {
                $this->notifier->publicationFailed($loaded['post'], $loaded['variant'], $workspace->publicId, $message, true);
            }
            $this->logger->warning('publish.reaped', ['publication' => $publication->publicId]);
        }

        return $count;
    }

    /**
     * @return int number of deletion jobs created
     */
    public function enqueueDeletions(): int
    {
        $ids = $this->system->dueForDeletion(3600, self::BATCH);
        foreach ($ids as $id) {
            $this->queue->dispatch(new DeletePublicationJob($id), 0, PublishJob::QUEUE);
        }
        $this->system->markDeletionEnqueued($ids);

        return count($ids);
    }
}
