<?php

declare(strict_types=1);

namespace App\Http\Controllers\Automation;

use App\Domain\Automation\AutomationException;
use App\Domain\Automation\Queue\EvergreenPool;
use App\Domain\Automation\Queue\EvergreenRepository;
use App\Domain\Automation\Queue\EvergreenService;
use App\Domain\Automation\Queue\PostQueue;
use App\Domain\Automation\Queue\QueueEntry;
use App\Domain\Automation\Queue\QueueRepository;
use App\Domain\Automation\Queue\QueueService;
use App\Domain\Billing\Entitlements;
use App\Domain\Channel\ChannelRepository;
use App\Domain\Post\Post;
use App\Domain\Post\PostRepository;
use App\Domain\Post\PostService;
use App\Domain\Post\PostStatus;
use App\Domain\Workspace\Permissions;
use App\Domain\Workspace\WorkspaceContext;
use App\Http\FormFlash;
use App\Http\WorkspaceRequest;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;
use App\Support\RuDates;
use DateTimeZone;

/**
 * Queues with slots and their evergreen pools: the list, a queue's page (slots, waiting posts in order, free slots ahead, pools),
 * creating and changing a queue, pausing it, moving and removing its posts, and putting a post into a queue from the post's page.
 */
final class QueueController
{
    private const DAYS = [1 => 'Понедельник', 2 => 'Вторник', 3 => 'Среда', 4 => 'Четверг', 5 => 'Пятница', 6 => 'Суббота', 7 => 'Воскресенье'];
    private const SHORT_DAYS = [1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб', 7 => 'Вс'];

    public function __construct(
        private readonly View $view,
        private readonly FormFlash $flash,
        private readonly AutomationHttp $http,
        private readonly QueueService $queues,
        private readonly QueueRepository $repository,
        private readonly EvergreenService $pools,
        private readonly EvergreenRepository $poolRepository,
        private readonly PostRepository $posts,
        private readonly PostService $postService,
        private readonly ChannelRepository $channels,
        private readonly Entitlements $entitlements,
        private readonly Permissions $permissions,
    ) {
    }

    public function index(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $zone = new DateTimeZone($context->timezone);
        $rows = [];
        foreach ($this->repository->all($context) as $queue) {
            $entries = $this->repository->entries($context, $queue);
            $next = $this->queues->upcomingSlots($context, $queue, 1)[0] ?? null;
            $rows[] = [
                'id' => $queue->publicId,
                'name' => $queue->name,
                'paused' => $queue->paused,
                'slots' => $this->slotSummary($queue),
                'waiting' => count($entries),
                'next' => $next === null ? null : RuDates::full($next->setTimezone($zone)),
            ];
        }

        return $this->view->response('workspace/automation/queues.twig', [
            'workspace' => $context,
            'queues' => $rows,
            'can_manage' => $this->canManage($context),
            'has_feature' => $this->entitlements->hasFeature($context->workspaceId, 'slots'),
            'base' => AutomationHttp::base($context),
        ]);
    }

    public function form(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $queue = $this->maybeQueue($context, $request);

        return $this->view->response('workspace/automation/queue_form.twig', [
            'workspace' => $context,
            'queue' => $queue,
            'days' => self::DAYS,
            'times' => $queue === null ? array_fill(1, 7, '') : $this->timesByDay($queue),
            'channels' => $this->http->channelChoices($context),
            'selected' => $queue === null ? [] : $this->selectedChannels($context, $queue),
            'timezone' => $context->timezone,
            'base' => AutomationHttp::base($context),
        ]);
    }

    public function store(Request $request): Response
    {
        return $this->save($request, null);
    }

    public function update(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);

        return $this->save($request, $this->queue($context, $request));
    }

    public function show(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $queue = $this->queue($context, $request);
        $zone = new DateTimeZone($context->timezone);
        $entries = [];
        foreach ($this->repository->entries($context, $queue) as $position => $entry) {
            $post = $this->posts->findById($context, $entry->postId);
            if ($post === null) {
                continue;
            }
            $entries[] = $this->entryRow($entry, $post, $zone, $position + 1);
        }
        $poolRows = [];
        foreach ($this->poolRepository->all($context) as $pool) {
            if ($pool->queueId !== $queue->id) {
                continue;
            }
            $poolRows[] = $this->poolRow($context, $pool, $zone);
        }
        $slots = array_map(static fn (\DateTimeImmutable $at): string => RuDates::full($at->setTimezone($zone)), $this->queues->upcomingSlots($context, $queue, count($entries) + 3));

        return $this->view->response('workspace/automation/queue.twig', [
            'workspace' => $context,
            'queue' => $queue,
            'slot_summary' => $this->slotSummary($queue),
            'channel_names' => array_map(static fn (array $c): string => $c['name'], array_values(array_filter($this->http->channelChoices($context), fn (array $c): bool => in_array($c['id'], $this->selectedChannels($context, $queue), true)))),
            'entries' => $entries,
            'upcoming' => array_slice($slots, count($entries)),
            'pools' => $poolRows,
            'drafts' => $this->draftChoices($context),
            'can_manage' => $this->canManage($context),
            'timezone' => $context->timezone,
            'base' => AutomationHttp::base($context),
        ]);
    }

    public function delete(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $queue = $this->queue($context, $request);
        try {
            $this->queues->delete($context, $queue);
        } catch (AutomationException $e) {
            return $this->http->refuse($context, $e, AutomationHttp::base($context) . '/queues/' . $queue->publicId);
        }
        $this->flash->toast('Очередь «' . $queue->name . '» удалена. Посты в ней остались запланированными.');

        return Response::redirect(AutomationHttp::base($context) . '/queues');
    }

    public function pause(Request $request): Response
    {
        return $this->toggle($request, true);
    }

    public function resume(Request $request): Response
    {
        return $this->toggle($request, false);
    }

    public function move(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $queue = $this->queue($context, $request);
        $post = $this->posts->find($context, AutomationHttp::id($request, 'postId')) ?? throw new HttpException(404, 'Not found');
        $delta = AutomationHttp::text($request, 'direction') === 'up' ? -1 : 1;
        try {
            $this->queues->move($context, $queue, $post, $delta);
        } catch (AutomationException $e) {
            return $this->http->refuse($context, $e, $this->queueUrl($context, $queue));
        }

        return Response::redirect($this->queueUrl($context, $queue));
    }

    public function remove(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $queue = $this->queue($context, $request);
        $post = $this->posts->find($context, AutomationHttp::id($request, 'postId')) ?? throw new HttpException(404, 'Not found');
        try {
            $this->queues->dequeue($context, $queue, $post);
        } catch (AutomationException $e) {
            return $this->http->refuse($context, $e, $this->queueUrl($context, $queue));
        }
        $this->flash->toast('Пост убран из очереди и сохранён как черновик.');

        return Response::redirect($this->queueUrl($context, $queue));
    }

    /**
     * From a post's page: put the post at the end of a chosen queue.
     */
    public function enqueue(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $post = $this->posts->find($context, AutomationHttp::id($request, 'postId')) ?? throw new HttpException(404, 'Not found');
        $back = AutomationHttp::base($context) . '/posts/' . $post->publicId;
        $queue = $this->repository->find($context, AutomationHttp::text($request, 'queue', 26));
        if ($queue === null) {
            $this->flash->toast('Выберите очередь.', 'error');

            return Response::redirect($back);
        }
        try {
            $placed = $this->queues->enqueue($context, $post, $queue);
        } catch (AutomationException $e) {
            return $this->http->refuse($context, $e, $back);
        }
        $when = $placed->scheduledAt === null ? '' : ' Выйдет ' . RuDates::full($placed->scheduledAt->setTimezone(new DateTimeZone($context->timezone))) . '.';
        $this->flash->toast('Пост стоит в очереди «' . $queue->name . '».' . $when);

        return Response::redirect($this->queueUrl($context, $queue));
    }

    // ---- evergreen pools --------------------------------------------------------------------------------------------

    public function poolStore(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $queue = $this->queue($context, $request);
        $back = $this->queueUrl($context, $queue);
        try {
            $this->pools->create($context, $queue, AutomationHttp::text($request, 'name', 100), (int) AutomationHttp::text($request, 'interval', 5), (int) AutomationHttp::text($request, 'keep_ahead', 3));
        } catch (AutomationException $e) {
            return $this->http->refuse($context, $e, $back);
        }
        $this->flash->toast('Пул создан. Добавьте в него посты.');

        return Response::redirect($back);
    }

    public function poolToggle(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        [$queue, $pool] = $this->poolOf($context, $request);
        try {
            $this->pools->setEnabled($context, $pool, !$pool->enabled);
        } catch (AutomationException $e) {
            return $this->http->refuse($context, $e, $this->queueUrl($context, $queue));
        }
        $this->flash->toast($pool->enabled ? 'Пул остановлен.' : 'Пул включён: он сам пополнит очередь.');

        return Response::redirect($this->queueUrl($context, $queue));
    }

    public function poolDelete(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        [$queue, $pool] = $this->poolOf($context, $request);
        try {
            $this->pools->delete($context, $pool);
        } catch (AutomationException $e) {
            return $this->http->refuse($context, $e, $this->queueUrl($context, $queue));
        }
        $this->flash->toast('Пул «' . $pool->name . '» удалён.');

        return Response::redirect($this->queueUrl($context, $queue));
    }

    public function poolAdd(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        [$queue, $pool] = $this->poolOf($context, $request);
        $post = $this->posts->find($context, AutomationHttp::text($request, 'post', 26));
        try {
            if ($post === null || !$this->postService->canSee($context, $post)) {
                throw new AutomationException('Выберите пост.');
            }
            $this->pools->addPost($context, $pool, $post);
        } catch (AutomationException $e) {
            return $this->http->refuse($context, $e, $this->queueUrl($context, $queue));
        }
        $this->flash->toast('Пост добавлен в пул.');

        return Response::redirect($this->queueUrl($context, $queue));
    }

    public function poolRemove(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        [$queue, $pool] = $this->poolOf($context, $request);
        $post = $this->posts->find($context, AutomationHttp::id($request, 'postId')) ?? throw new HttpException(404, 'Not found');
        try {
            $this->pools->removePost($context, $pool, $post);
        } catch (AutomationException $e) {
            return $this->http->refuse($context, $e, $this->queueUrl($context, $queue));
        }

        return Response::redirect($this->queueUrl($context, $queue));
    }

    // ---- internals --------------------------------------------------------------------------------------------------

    private function save(Request $request, ?PostQueue $queue): Response
    {
        $context = WorkspaceRequest::context($request);
        $name = AutomationHttp::text($request, 'name', 100);
        $channelIds = AutomationHttp::ids($request, 'channels');
        $raw = $request->input('slots');
        $times = [];
        $slots = [];
        $problem = null;
        foreach (self::DAYS as $day => $_) {
            $text = is_array($raw) && is_string($raw[$day] ?? null) ? mb_substr($raw[$day], 0, 200) : '';
            $times[$day] = $text;
            foreach (preg_split('/[\s,;]+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $time) {
                if (preg_match('/^(\d{1,2})[:.](\d{2})$/', $time, $m) !== 1 || (int) $m[1] > 23 || (int) $m[2] > 59) {
                    $problem ??= 'Время «' . mb_substr($time, 0, 12) . '» не понятно. Пишите его так: 10:00 или 18:30.';
                    continue;
                }
                $slots[] = ['weekday' => $day, 'time' => sprintf('%02d:%02d', (int) $m[1], (int) $m[2])];
            }
        }
        $back = AutomationHttp::base($context) . '/queues/' . ($queue === null ? 'new' : $queue->publicId . '/edit');
        $input = ['name' => $name, 'slots' => $times, 'channels' => $channelIds];
        try {
            if ($problem !== null) {
                throw new AutomationException($problem);
            }
            if ($queue === null) {
                $queue = $this->queues->create($context, $name, $slots, $channelIds);
                $this->flash->toast('Очередь «' . $queue->name . '» создана. Ставьте в неё посты кнопкой «В очередь».');
            } else {
                $this->queues->update($context, $queue, $name, $slots, $channelIds);
                $this->flash->toast('Очередь сохранена. Посты в ней встали по новым слотам.');
            }
        } catch (AutomationException $e) {
            return $this->http->refuse($context, $e, $back, $input);
        }

        return Response::redirect($this->queueUrl($context, $queue));
    }

    private function toggle(Request $request, bool $pause): Response
    {
        $context = WorkspaceRequest::context($request);
        $queue = $this->queue($context, $request);
        try {
            $pause ? $this->queues->pause($context, $queue) : $this->queues->resume($context, $queue);
        } catch (AutomationException $e) {
            return $this->http->refuse($context, $e, $this->queueUrl($context, $queue));
        }
        $this->flash->toast($pause ? 'Очередь на паузе: её посты вернулись в черновики и ждут.' : 'Очередь работает: посты снова встали по слотам.');

        return Response::redirect($this->queueUrl($context, $queue));
    }

    private function canManage(WorkspaceContext $context): bool
    {
        return $this->permissions->allows($context->role, 'posts.publish');
    }

    private function queue(WorkspaceContext $context, Request $request): PostQueue
    {
        return $this->repository->find($context, AutomationHttp::id($request, 'queueId')) ?? throw new HttpException(404, 'Not found');
    }

    private function maybeQueue(WorkspaceContext $context, Request $request): ?PostQueue
    {
        $params = $request->attribute('route_params');

        return is_array($params) && isset($params['queueId']) ? $this->queue($context, $request) : null;
    }

    /**
     * @return array{PostQueue, EvergreenPool}
     */
    private function poolOf(WorkspaceContext $context, Request $request): array
    {
        $queue = $this->queue($context, $request);
        $pool = $this->poolRepository->find($context, AutomationHttp::id($request, 'poolId'));
        if ($pool === null || $pool->queueId !== $queue->id) {
            throw new HttpException(404, 'Not found');
        }

        return [$queue, $pool];
    }

    private function queueUrl(WorkspaceContext $context, PostQueue $queue): string
    {
        return AutomationHttp::base($context) . '/queues/' . $queue->publicId;
    }

    /**
     * "Пн–Пт 10:00, 15:00" style summary, one line per group of days with the same times.
     */
    private function slotSummary(PostQueue $queue): string
    {
        $byDay = [];
        foreach ($queue->slots as $slot) {
            $byDay[$slot['weekday']][] = $slot['time'];
        }
        $groups = [];
        foreach ($byDay as $day => $times) {
            $groups[implode(', ', $times)][] = $day;
        }
        $parts = [];
        foreach ($groups as $times => $days) {
            sort($days);
            $label = count($days) > 1 && $days === range($days[0], $days[array_key_last($days)]) ? self::SHORT_DAYS[$days[0]] . '–' . self::SHORT_DAYS[$days[array_key_last($days)]] : implode(', ', array_map(static fn (int $d): string => self::SHORT_DAYS[$d], $days));
            $parts[] = $label . ' ' . $times;
        }

        return implode('; ', $parts);
    }

    /**
     * @return array<int, string>
     */
    private function timesByDay(PostQueue $queue): array
    {
        $out = array_fill(1, 7, '');
        foreach ($queue->slots as $slot) {
            $out[$slot['weekday']] = ($out[$slot['weekday']] === '' ? '' : $out[$slot['weekday']] . ', ') . $slot['time'];
        }

        return $out;
    }

    /**
     * @return list<string> public ids of the queue's channels
     */
    private function selectedChannels(WorkspaceContext $context, PostQueue $queue): array
    {
        $ids = [];
        foreach ($queue->channelIds as $id) {
            $channel = $this->channels->findById($context, $id);
            if ($channel !== null) {
                $ids[] = $channel->publicId;
            }
        }

        return $ids;
    }

    /**
     * @return array<string, mixed>
     */
    private function entryRow(QueueEntry $entry, Post $post, DateTimeZone $zone, int $position): array
    {
        return [
            'position' => $position,
            'post_id' => $post->publicId,
            'title' => $post->title(90),
            'status' => $post->status->badge(),
            'status_label' => $post->status->label(),
            'when' => $post->status === PostStatus::Scheduled && $post->scheduledAt !== null ? RuDates::full($post->scheduledAt->setTimezone($zone)) : null,
            'note' => $entry->note,
            'evergreen' => str_starts_with($entry->source, 'evergreen'),
            'source' => $entry->source === 'rss' ? 'из ленты' : (str_starts_with($entry->source, 'evergreen') ? 'из пула' : null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function poolRow(WorkspaceContext $context, EvergreenPool $pool, DateTimeZone $zone): array
    {
        $posts = [];
        foreach ($this->poolRepository->posts($context, $pool) as $item) {
            $post = $this->posts->findById($context, $item['post_id']);
            if ($post !== null) {
                $posts[] = ['id' => $post->publicId, 'title' => $post->title(80), 'uses' => $item['uses'], 'last' => $item['last_used_at'] === null ? null : RuDates::full($item['last_used_at']->setTimezone($zone))];
            }
        }

        return ['id' => $pool->publicId, 'name' => $pool->name, 'enabled' => $pool->enabled, 'interval' => $pool->minIntervalDays, 'keep_ahead' => $pool->keepAhead, 'waiting' => $this->poolRepository->waitingCopies($context, $pool), 'posts' => $posts];
    }

    /**
     * @return list<array{id: string, title: string}>
     */
    private function draftChoices(WorkspaceContext $context): array
    {
        return array_map(static fn (Post $p): array => ['id' => $p->publicId, 'title' => $p->title(70)], $this->posts->drafts($context, $this->postService->allowedChannels($context), 100));
    }
}
