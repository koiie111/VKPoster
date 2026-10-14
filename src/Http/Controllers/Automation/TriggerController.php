<?php

declare(strict_types=1);

namespace App\Http\Controllers\Automation;

use App\Domain\Automation\AutomationException;
use App\Domain\Automation\Trigger\TriggerAction;
use App\Domain\Automation\Trigger\TriggerEvent;
use App\Domain\Automation\Trigger\TriggerRepository;
use App\Domain\Automation\Trigger\TriggerService;
use App\Domain\Channel\ChannelRepository;
use App\Domain\Post\PostRepository;
use App\Domain\Post\PostService;
use App\Http\FormFlash;
use App\Http\WorkspaceRequest;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;

/**
 * "If → then" rules: add one to a post or to a channel, delete one, and the channel's rules page (a post shows the same block on its own page).
 */
final class TriggerController
{
    private const UNITS = ['minutes' => 1, 'hours' => 60, 'days' => 1440];

    public function __construct(
        private readonly View $view,
        private readonly FormFlash $flash,
        private readonly AutomationHttp $http,
        private readonly TriggerService $service,
        private readonly TriggerRepository $repository,
        private readonly TriggerPanel $panel,
        private readonly PostRepository $posts,
        private readonly PostService $postService,
        private readonly ChannelRepository $channels,
    ) {
    }

    public function channelPage(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $channel = $this->channels->find($context, AutomationHttp::id($request, 'channelId')) ?? throw new HttpException(404, 'Not found');

        return $this->view->response('workspace/automation/channel_triggers.twig', [
            'workspace' => $context,
            'channel' => ['id' => $channel->publicId, 'name' => $channel->displayName()],
            'panel' => $this->panel->forChannel($context, $channel),
            'base' => AutomationHttp::base($context),
        ]);
    }

    public function storeForPost(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $post = $this->posts->find($context, AutomationHttp::id($request, 'postId'));
        if ($post === null || !$this->postService->canSee($context, $post)) {
            throw new HttpException(404, 'Not found');
        }

        return $this->store($request, $post, null, AutomationHttp::base($context) . '/posts/' . $post->publicId);
    }

    public function storeForChannel(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $channel = $this->channels->find($context, AutomationHttp::id($request, 'channelId')) ?? throw new HttpException(404, 'Not found');
        $allowed = $this->postService->allowedChannels($context);
        if ($allowed !== null && !in_array($channel->id, $allowed, true)) {
            throw new HttpException(404, 'Not found');
        }

        return $this->store($request, null, $channel, AutomationHttp::base($context) . '/channels/' . $channel->publicId . '/triggers');
    }

    public function delete(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $trigger = $this->repository->find($context, AutomationHttp::id($request, 'triggerId')) ?? throw new HttpException(404, 'Not found');
        $back = AutomationHttp::base($context) . (AutomationHttp::text($request, 'back', 20) === 'channel' && $trigger->channelId !== null ? '/channels/' . ($this->channels->findById($context, $trigger->channelId)->publicId ?? '') . '/triggers' : '/posts/' . ($trigger->postId === null ? '' : ($this->posts->findById($context, $trigger->postId)->publicId ?? '')));
        try {
            $this->service->delete($context, $trigger);
        } catch (AutomationException $e) {
            return $this->http->refuse($context, $e, $back);
        }
        $this->flash->toast('Правило удалено.');

        return Response::redirect($back);
    }

    private function store(Request $request, ?\App\Domain\Post\Post $post, ?\App\Domain\Channel\Channel $channel, string $back): Response
    {
        $context = WorkspaceRequest::context($request);
        $event = TriggerEvent::tryFrom(AutomationHttp::text($request, 'event', 16));
        $action = TriggerAction::tryFrom(AutomationHttp::text($request, 'action', 24));
        $delay = AutomationHttp::text($request, 'delay', 6);
        $unit = self::UNITS[AutomationHttp::text($request, 'delay_unit', 8)] ?? 60;
        try {
            if ($event === null || $action === null) {
                throw new AutomationException('Выберите, когда правило должно сработать и что сделать.');
            }
            $this->service->create($context, $post, $channel, $event, ctype_digit($delay) ? (int) $delay * $unit : 0, $action, [
                'date' => AutomationHttp::text($request, 'date', 10),
                'time' => AutomationHttp::text($request, 'time', 5),
                'queue' => AutomationHttp::text($request, 'queue', 26),
                'next_post' => AutomationHttp::text($request, 'next_post', 26),
                'message' => AutomationHttp::text($request, 'message', 300),
            ]);
        } catch (AutomationException $e) {
            return $this->http->refuse($context, $e, $back);
        }
        $this->flash->toast('Правило добавлено. Оно сработает один раз.');

        return Response::redirect($back);
    }
}
