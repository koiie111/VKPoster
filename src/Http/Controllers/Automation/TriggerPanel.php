<?php

declare(strict_types=1);

namespace App\Http\Controllers\Automation;

use App\Domain\Automation\Queue\QueueRepository;
use App\Domain\Automation\Trigger\Trigger;
use App\Domain\Automation\Trigger\TriggerAction;
use App\Domain\Automation\Trigger\TriggerEvent;
use App\Domain\Automation\Trigger\TriggerRepository;
use App\Domain\Billing\Entitlements;
use App\Domain\Channel\Channel;
use App\Domain\Post\Post;
use App\Domain\Post\PostRepository;
use App\Domain\Post\PostService;
use App\Domain\Workspace\Permissions;
use App\Domain\Workspace\WorkspaceContext;
use App\Support\RuDates;
use DateTimeZone;

/**
 * The data of the "if → then" block shown on a post's page and on a channel's rules page: the triggers already set, in words, and what
 * the form needs (queues, drafts that can follow, the actions that make sense in this place).
 */
final class TriggerPanel
{
    public function __construct(
        private readonly TriggerRepository $triggers,
        private readonly QueueRepository $queues,
        private readonly PostRepository $posts,
        private readonly PostService $postService,
        private readonly Entitlements $entitlements,
        private readonly Permissions $permissions,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function forPost(WorkspaceContext $context, Post $post): array
    {
        return $this->build($context, $this->triggers->forPost($context, $post->id), $post, null, '/w/' . $context->workspacePublicId . '/posts/' . $post->publicId . '/triggers');
    }

    /**
     * @return array<string, mixed>
     */
    public function forChannel(WorkspaceContext $context, Channel $channel): array
    {
        return $this->build($context, $this->triggers->forChannel($context, $channel->id), null, $channel, '/w/' . $context->workspacePublicId . '/channels/' . $channel->publicId . '/triggers');
    }

    /**
     * @param list<Trigger> $triggers
     * @return array<string, mixed>
     */
    private function build(WorkspaceContext $context, array $triggers, ?Post $post, ?Channel $channel, string $action): array
    {
        $zone = new DateTimeZone($context->timezone);
        $rows = [];
        foreach ($triggers as $trigger) {
            $rows[] = [
                'id' => $trigger->publicId,
                'sentence' => $this->sentence($context, $trigger, $zone),
                'status' => $trigger->status,
                'status_label' => match ($trigger->status) {
                    'pending' => 'ждёт события',
                    'armed' => $trigger->fireAt === null ? 'ждёт' : 'сработает ' . RuDates::full($trigger->fireAt->setTimezone($zone)),
                    'done' => 'сработало' . ($trigger->firedAt === null ? '' : ' ' . RuDates::full($trigger->firedAt->setTimezone($zone))),
                    default => 'не получилось',
                },
                'result' => $trigger->result,
                'failed' => $trigger->status === 'failed',
            ];
        }
        $actions = [];
        foreach (TriggerAction::cases() as $case) {
            if ($post === null && $case->actsOnPost()) {
                $actions[$case->value] = $case->label() . ' (тот, что вышел в этом канале)';
            } else {
                $actions[$case->value] = $case->label();
            }
        }
        $events = [];
        foreach (TriggerEvent::cases() as $case) {
            $events[$case->value] = $case->label();
        }

        return [
            'rows' => $rows,
            'action' => $action,
            'events' => $events,
            'actions' => $actions,
            'queues' => array_map(static fn ($q): array => ['id' => $q->publicId, 'name' => $q->name], $this->queues->all($context)),
            'drafts' => array_map(static fn (Post $p): array => ['id' => $p->publicId, 'title' => $p->title(60)], array_values(array_filter($this->posts->drafts($context, $this->postService->allowedChannels($context), 100), static fn (Post $p): bool => $post === null || $p->id !== $post->id))),
            'can_manage' => $this->permissions->allows($context->role, 'posts.publish'),
            'has_feature' => $this->entitlements->hasFeature($context->workspaceId, 'slots'),
            'timezone' => $context->timezone,
            'for_post' => $post !== null,
        ];
    }

    private function sentence(WorkspaceContext $context, Trigger $trigger, DateTimeZone $zone): string
    {
        $when = match ($trigger->event) {
            TriggerEvent::Published => 'Когда пост опубликован' . ($trigger->delayMinutes > 0 ? ' и пройдёт ' . self::duration($trigger->delayMinutes) : ''),
            TriggerEvent::AtTime => 'В ' . ($trigger->fireAt === null ? 'назначенное время' : RuDates::full($trigger->fireAt->setTimezone($zone))),
            TriggerEvent::Failed => 'Если публикация не удалась',
        };
        $what = $trigger->action->label();
        if ($trigger->action === TriggerAction::PauseQueue || $trigger->action === TriggerAction::ResumeQueue) {
            $queue = isset($trigger->params['queue_id']) && is_int($trigger->params['queue_id']) ? $this->queues->findById($context, $trigger->params['queue_id']) : null;
            $what .= $queue === null ? '' : ' «' . $queue->name . '»';
        } elseif ($trigger->action === TriggerAction::PublishNext) {
            $next = isset($trigger->params['next_post_id']) && is_int($trigger->params['next_post_id']) ? $this->posts->findById($context, $trigger->params['next_post_id']) : null;
            $what .= $next === null ? '' : ': «' . $next->title(40) . '»';
        } elseif ($trigger->action === TriggerAction::Notify && is_string($trigger->params['message'] ?? null)) {
            $what .= ': «' . mb_substr($trigger->params['message'], 0, 60) . '»';
        }

        return $when . ' → ' . $what;
    }

    public static function duration(int $minutes): string
    {
        if ($minutes % 1440 === 0) {
            $days = intdiv($minutes, 1440);

            return $days . ' ' . ($days % 10 === 1 && $days % 100 !== 11 ? 'день' : ($days % 10 >= 2 && $days % 10 <= 4 && ($days % 100 < 10 || $days % 100 >= 20) ? 'дня' : 'дней'));
        }
        if ($minutes % 60 === 0) {
            $hours = intdiv($minutes, 60);

            return $hours . ' ' . ($hours % 10 === 1 && $hours % 100 !== 11 ? 'час' : ($hours % 10 >= 2 && $hours % 10 <= 4 && ($hours % 100 < 10 || $hours % 100 >= 20) ? 'часа' : 'часов'));
        }

        return $minutes . ' мин.';
    }
}
