<?php

declare(strict_types=1);

namespace App\Domain\Automation\Trigger;

use App\Domain\Audit\AuditLog;
use App\Domain\Automation\Queue\QueueRepository;
use App\Domain\Automation\Queue\QueueService;
use App\Domain\Channel\ChannelCredentials;
use App\Domain\Channel\ChannelSystem;
use App\Domain\Notification\Notifier;
use App\Domain\Post\Post;
use App\Domain\Post\PostException;
use App\Domain\Post\PostRepository;
use App\Domain\Post\PostService;
use App\Domain\Post\PostStatus;
use App\Domain\Post\PostVariant;
use App\Domain\Post\PublicationSystem;
use App\Domain\Post\PublishingContexts;
use App\Domain\Workspace\WorkspaceContext;
use App\Integrations\Social\Contracts\PlatformError;
use App\Integrations\Social\Contracts\PublishResult;
use App\Integrations\Social\PlatformRegistry;
use App\Kernel\Database\Connection;
use App\Kernel\Queue\Queue;
use App\Support\Clock;
use App\Support\DbTime;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Carries triggers out. Publishing tells the engine what happened (`onPublished`, `onFailed`); a waiting trigger that matches is
 * armed with the moment it should fire (now plus its delay). `tick()` (every minute) and `FireTriggerJob` fire the armed ones.
 *
 * Exactly once: a trigger is armed by `UPDATE … WHERE status = 'pending'` and fired by `UPDATE … WHERE status = 'armed'`; each
 * statement succeeds for one caller only, so two workers, a repeated event or a repeated tick can not run an action twice. The
 * trigger is marked done before its action runs: a crash loses the action rather than doing it twice, which for "delete a post" or
 * "publish the next one" is the safe side. Whatever happens, publishing never fails because of a trigger.
 */
final class TriggerEngine
{
    public function __construct(
        private readonly Connection $db,
        private readonly TriggerRepository $triggers,
        private readonly PublicationSystem $publications,
        private readonly PlatformRegistry $registry,
        private readonly ChannelSystem $channels,
        private readonly ChannelCredentials $credentials,
        private readonly PostRepository $posts,
        private readonly PostService $service,
        private readonly QueueRepository $queues,
        private readonly QueueService $queueService,
        private readonly Notifier $notifier,
        private readonly PublishingContexts $contexts,
        private readonly Queue $queue,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * A copy of the post went out in a channel.
     */
    public function onPublished(Post $post, PostVariant $variant): void
    {
        $this->arm(TriggerEvent::Published, $post, $variant->channelId);
    }

    /**
     * A publication of the post failed for good (not an uncertain outcome, not a retry).
     */
    public function onFailed(Post $post, PostVariant $variant): void
    {
        $this->arm(TriggerEvent::Failed, $post, $variant->channelId);
    }

    /**
     * Every minute: fire what is due.
     *
     * @return int triggers fired
     */
    public function tick(): int
    {
        $rows = $this->db->select('SELECT id FROM triggers WHERE status = ? AND fire_at <= ? ORDER BY fire_at LIMIT 200', ['armed', DbTime::format($this->clock->now())]);
        $fired = 0;
        foreach ($rows as $row) {
            $fired += $this->fire((int) $row['id']) ? 1 : 0;
        }

        return $fired;
    }

    /**
     * Fire one armed trigger whose time has come. Returns false when it was not armed, not yet due, or somebody else fired it.
     */
    public function fire(int $id): bool
    {
        $now = DbTime::format($this->clock->now());
        $claimed = $this->db->execute('UPDATE triggers SET status = ?, fired_at = ? WHERE id = ? AND status = ? AND fire_at <= ?', ['done', $now, $id, 'armed', $now]);
        if ($claimed !== 1) {
            return false;
        }
        $row = $this->db->table('triggers')->where('id', '=', $id)->first();
        if ($row === null) {
            return false;
        }
        $trigger = TriggerRepository::hydrate($row);
        $context = $this->contexts->forWorkspace($trigger->workspaceId, $trigger->createdBy);
        try {
            if ($context === null) {
                throw new RuntimeException('В пространстве не осталось участников, от имени которых можно действовать.');
            }
            $result = $this->run($context, $trigger);
            $this->db->table('triggers')->where('id', '=', $id)->update(['result' => mb_substr($result, 0, 255)]);
            $this->audit->record('trigger.fired', null, 'trigger', $trigger->publicId, ['action' => $trigger->action->value], $trigger->workspaceId);
        } catch (Throwable $e) {
            $message = $e instanceof RuntimeException || $e instanceof PostException || $e instanceof PlatformError ? ($e instanceof PlatformError ? $e->forUser() : $e->getMessage()) : 'Не удалось выполнить действие.';
            $this->db->table('triggers')->where('id', '=', $id)->update(['status' => 'failed', 'result' => mb_substr($message, 0, 255)]);
            $this->logger->warning('trigger.failed', ['trigger' => $trigger->publicId, 'exception' => $e::class]);
            if ($context !== null && $trigger->action !== TriggerAction::Notify) {
                $this->notifier->automationNotice($trigger->workspaceId, 'Правило не сработало', $trigger->action->label() . ': ' . $message, '/w/' . $context->workspacePublicId . ($trigger->postId !== null ? '' : '/channels'));
            }
        }

        return true;
    }

    private function arm(TriggerEvent $event, Post $post, ?int $channelId): void
    {
        try {
            $rows = $this->db->select(
                'SELECT id, delay_minutes FROM triggers WHERE status = ? AND event = ? AND workspace_id = ? AND (post_id = ? OR (channel_id IS NOT NULL AND channel_id = ?))',
                ['pending', $event->value, $post->workspaceId, $post->id, $channelId ?? 0],
            );
            foreach ($rows as $row) {
                $delay = (int) $row['delay_minutes'];
                $at = $this->clock->now()->modify('+' . $delay . ' minutes');
                $armed = $this->db->execute('UPDATE triggers SET status = ?, fire_at = ?, event_post_id = ? WHERE id = ? AND status = ?', ['armed', DbTime::format($at), $post->id, (int) $row['id'], 'pending']);
                if ($armed === 1 && $delay === 0) {
                    $this->queue->dispatch(new FireTriggerJob((int) $row['id']));
                }
            }
        } catch (Throwable $e) {
            $this->logger->error('trigger.arm_failed', ['exception' => $e]);
        }
    }

    /**
     * @return string what was done, for the person
     * @throws RuntimeException|PostException|PlatformError
     */
    private function run(WorkspaceContext $context, Trigger $trigger): string
    {
        $channelId = $trigger->postId === null ? $trigger->channelId : null;
        switch ($trigger->action) {
            case TriggerAction::Delete:
                $count = $this->publications->deleteNow($this->targetPost($trigger), $channelId);

                return $count === 0 ? 'Нечего удалять: пост не опубликован.' : sprintf('Поставлено на удаление: %d.', $count);
            case TriggerAction::Pin:
            case TriggerAction::Unpin:
                return $this->pin($trigger, $this->targetPost($trigger), $channelId, $trigger->action === TriggerAction::Pin);
            case TriggerAction::PublishNext:
                $id = $trigger->params['next_post_id'] ?? null;
                $next = is_int($id) ? $this->posts->findById($context, $id) : null;
                if ($next === null || $next->status !== PostStatus::Draft) {
                    throw new RuntimeException('Следующий пост удалён или уже не черновик.');
                }
                $this->service->planExisting($context, $next, $this->clock->now(), true);

                return 'Следующий пост отправлен на публикацию.';
            case TriggerAction::PauseQueue:
            case TriggerAction::ResumeQueue:
                $id = $trigger->params['queue_id'] ?? null;
                $queue = is_int($id) ? $this->queues->findById($context, $id) : null;
                if ($queue === null) {
                    throw new RuntimeException('Очередь удалена.');
                }
                $trigger->action === TriggerAction::PauseQueue ? $this->queueService->pause($context, $queue) : $this->queueService->resume($context, $queue);

                return $trigger->action === TriggerAction::PauseQueue ? 'Очередь на паузе.' : 'Очередь снята с паузы.';
            case TriggerAction::Notify:
                $message = is_string($trigger->params['message'] ?? null) ? $trigger->params['message'] : 'Сработало ваше правило.';
                $post = $trigger->targetPostId() === null ? null : $this->posts->findById($context, $trigger->targetPostId());
                $this->notifier->automationNotice($trigger->workspaceId, 'Сработало правило', $message, '/w/' . $context->workspacePublicId . ($post === null ? '/calendar' : '/posts/' . $post->publicId));

                return 'Уведомление отправлено.';
        }
    }

    private function targetPost(Trigger $trigger): int
    {
        return $trigger->targetPostId() ?? throw new RuntimeException('Не к чему применить действие: нет поста.');
    }

    private function pin(Trigger $trigger, int $postId, ?int $channelId, bool $pin): string
    {
        $done = 0;
        $problems = [];
        foreach ($this->publications->liveCopies($postId, $channelId) as $copy) {
            $channel = $copy->channelId === null ? null : $this->channels->find($copy->channelId);
            if ($channel === null || !$this->registry->isEnabled($channel->platform)) {
                continue;
            }
            $adapter = $this->registry->adapter($channel->platform);
            if (!$adapter->capabilities()->pin) {
                $problems[] = $channel->platform->label() . ' не умеет закреплять посты.';
                continue;
            }
            try {
                $adapter->pin(new PublishResult((string) $copy->externalPostId, $copy->externalUrl, $copy->externalIds), $channel->externalId, $this->credentials->forChannel($channel), $pin);
                $this->publications->markPinned($copy, $pin);
                ++$done;
            } catch (PlatformError $e) {
                $problems[] = $channel->displayName() . ': ' . $e->forUser();
            }
        }
        if ($done === 0 && $problems !== []) {
            throw new RuntimeException(implode(' ', $problems));
        }

        return ($pin ? 'Закреплено: ' : 'Откреплено: ') . $done . ($problems === [] ? '.' : '. ' . implode(' ', $problems));
    }
}
