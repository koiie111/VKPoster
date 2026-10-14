<?php

declare(strict_types=1);

namespace App\Domain\Automation\Trigger;

use App\Domain\Audit\AuditLog;
use App\Domain\Automation\AutomationException;
use App\Domain\Automation\Queue\QueueRepository;
use App\Domain\Billing\Entitlements;
use App\Domain\Billing\PlanLimitException;
use App\Domain\Channel\Channel;
use App\Domain\Post\Post;
use App\Domain\Post\PostException;
use App\Domain\Post\PostRepository;
use App\Domain\Post\PostService;
use App\Domain\Post\PostStatus;
use App\Domain\Post\ScheduleTime;
use App\Domain\Workspace\Permissions;
use App\Domain\Workspace\WorkspaceContext;
use App\Support\Clock;

/**
 * Creating and removing triggers, with the rules that make them sensible: which event goes with which action, what the action
 * needs (a queue, a following post, a message), and how many a post or a workspace may have.
 */
final class TriggerService
{
    public const MAX_PER_POST = 10;
    public const MAX_PER_WORKSPACE = 200;
    public const MAX_DELAY_MINUTES = 60 * 24 * 365;

    public function __construct(
        private readonly TriggerRepository $triggers,
        private readonly PostRepository $posts,
        private readonly PostService $service,
        private readonly QueueRepository $queues,
        private readonly Entitlements $entitlements,
        private readonly Permissions $permissions,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param array{date?: string, time?: string, queue?: string, next_post?: string, message?: string} $input what the form holds for the chosen action
     * @throws AutomationException
     */
    public function create(WorkspaceContext $context, ?Post $post, ?Channel $channel, TriggerEvent $event, int $delayMinutes, TriggerAction $action, array $input): Trigger
    {
        if (!$this->permissions->allows($context->role, 'posts.publish')) {
            throw new AutomationException('Правила настраивают редакторы и администраторы.', forbidden: true);
        }
        try {
            $this->entitlements->assertFeature($context->workspaceId, 'slots', 'Триггеры');
        } catch (PlanLimitException $e) {
            throw new AutomationException($e->getMessage(), planLimit: true);
        }
        if (($post === null) === ($channel === null)) {
            throw new AutomationException('Правило относится либо к посту, либо к каналу.');
        }
        if ($post !== null && !$this->service->canSee($context, $post)) {
            throw new AutomationException('Пост не найден.');
        }
        $this->assertCombination($post, $event, $action);
        if ($this->triggers->count($context) >= self::MAX_PER_WORKSPACE || ($post !== null && $this->triggers->countForPost($context, $post->id) >= self::MAX_PER_POST)) {
            throw new AutomationException('Правил уже слишком много. Удалите те, что больше не нужны.');
        }
        if ($event !== TriggerEvent::Published) {
            $delayMinutes = 0;
        } elseif ($delayMinutes < 0 || $delayMinutes > self::MAX_DELAY_MINUTES) {
            throw new AutomationException('Задержка — от 0 минут до года.');
        }

        $fireAt = null;
        if ($event === TriggerEvent::AtTime) {
            try {
                $fireAt = ScheduleTime::parse($input['date'] ?? '', $input['time'] ?? '', $context->timezone, $this->clock->now());
            } catch (PostException $e) {
                throw new AutomationException($e->getMessage());
            }
        }
        $params = $this->params($context, $post, $action, $input);
        $trigger = $this->triggers->create($context, $post?->id, $channel?->id, $event, $delayMinutes, $fireAt, $action, $params);
        $this->audit->record('trigger.created', $context->userId, 'trigger', $trigger->publicId, ['event' => $event->value, 'action' => $action->value], $context->workspaceId);

        return $trigger;
    }

    public function delete(WorkspaceContext $context, Trigger $trigger): void
    {
        if (!$this->permissions->allows($context->role, 'posts.publish')) {
            throw new AutomationException('Правила настраивают редакторы и администраторы.', forbidden: true);
        }
        $this->triggers->delete($context, $trigger);
        $this->audit->record('trigger.deleted', $context->userId, 'trigger', $trigger->publicId, [], $context->workspaceId);
    }

    /**
     * @throws AutomationException
     */
    private function assertCombination(?Post $post, TriggerEvent $event, TriggerAction $action): void
    {
        if ($action->actsOnPost() && $event === TriggerEvent::Failed) {
            throw new AutomationException('Удалить или закрепить можно только вышедший пост, а при ошибке он не вышел.');
        }
        if ($action->actsOnPost() && $post === null && $event === TriggerEvent::AtTime) {
            throw new AutomationException('Для канала «закрепить» и «удалить» работают после публикации поста, а не по времени.');
        }
        if ($action === TriggerAction::PublishNext && $post !== null && $event === TriggerEvent::Failed) {
            throw new AutomationException('Следующий пост после ошибки не публикуем: сначала разберитесь с ошибкой.');
        }
    }

    /**
     * @param array{date?: string, time?: string, queue?: string, next_post?: string, message?: string} $input
     * @return array<string, mixed>
     * @throws AutomationException
     */
    private function params(WorkspaceContext $context, ?Post $post, TriggerAction $action, array $input): array
    {
        switch ($action) {
            case TriggerAction::PauseQueue:
            case TriggerAction::ResumeQueue:
                $queue = $this->queues->find($context, $input['queue'] ?? '') ?? throw new AutomationException('Выберите очередь.');

                return ['queue_id' => $queue->id];
            case TriggerAction::PublishNext:
                $next = $this->posts->find($context, $input['next_post'] ?? '') ?? throw new AutomationException('Выберите следующий пост.');
                if ($next->status !== PostStatus::Draft || ($post !== null && $next->id === $post->id) || !$this->service->canSee($context, $next)) {
                    throw new AutomationException('Следующим может быть только другой пост-черновик.');
                }

                return ['next_post_id' => $next->id];
            case TriggerAction::Notify:
                $message = trim($input['message'] ?? '');

                return ['message' => mb_substr($message === '' ? 'Сработало ваше правило.' : $message, 0, 300)];
            default:
                return [];
        }
    }
}
