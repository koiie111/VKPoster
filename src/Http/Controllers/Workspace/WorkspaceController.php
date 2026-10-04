<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workspace;

use App\Domain\Billing\PlanLimitException;
use App\Domain\Channel\ChannelRepository;
use App\Domain\Post\CalendarItem;
use App\Domain\Post\CalendarRepository;
use App\Domain\Post\PostService;
use App\Domain\Workspace\WorkspaceService;
use App\Support\Clock;
use App\Support\RuDates;
use DateTimeZone;
use App\Http\FormFlash;
use App\Http\WorkspaceRequest;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\Validation\Validator;
use App\Kernel\View\View;

/**
 * Workspace home page and creating an additional workspace.
 */
final class WorkspaceController
{
    public function __construct(
        private readonly View $view,
        private readonly Validator $validator,
        private readonly WorkspaceService $service,
        private readonly FormFlash $flash,
        private readonly CalendarRepository $calendar,
        private readonly ChannelRepository $channels,
        private readonly PostService $posts,
        private readonly Clock $clock,
    ) {
    }

    /**
     * The start page: what is coming up, what needs attention, and the next step for a workspace that has no channels yet.
     */
    public function home(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $allowed = $this->posts->allowedChannels($context);
        $zone = new DateTimeZone($context->timezone);
        $now = $this->clock->now();
        $row = static fn (CalendarItem $item): array => [
            'platform' => $item->platform->badgeKey(),
            'time' => RuDates::dayMonth($item->at->setTimezone($zone)) . ', ' . $item->at->setTimezone($zone)->format('H:i'),
            'title' => $item->title,
            'channel' => $item->channelName,
            'status' => $item->status->badge(),
            'href' => '/w/' . $context->workspacePublicId . '/posts/' . $item->postId,
        ];
        $upcoming = array_slice($this->calendar->items($context, $now, $now->modify('+30 days'), null, ['scheduled'], null, $allowed, 8), 0, 6);

        return $this->view->response('workspace/home.twig', [
            'user' => WorkspaceRequest::user($request),
            'workspace' => $context,
            'channel_count' => count($this->channels->all($context, $allowed)),
            'upcoming' => array_map($row, $upcoming),
            'attention' => array_map($row, $this->calendar->needingAttention($context, $allowed, 5)),
            'base' => '/w/' . $context->workspacePublicId,
        ]);
    }

    public function create(): Response
    {
        return $this->view->response('workspace/new.twig');
    }

    public function store(Request $request): Response
    {
        $user = WorkspaceRequest::user($request);
        $name = WorkspaceRequest::text($request->input('name'));
        $errors = $this->validator->make(['name' => $name], ['name' => 'required|string|min:2|max:100'], ['name' => 'Название'])->errors();
        $workspace = null;
        $planLimit = null;
        if ($errors === []) {
            try {
                $workspace = $this->service->create($user, $name);
            } catch (PlanLimitException $e) {
                $planLimit = $e;
            }
        }
        if ($planLimit !== null) {
            $errors['name'] = [$planLimit->getMessage()];
        } elseif ($errors === [] && $workspace === null) {
            $errors['name'] = ['Можно создать не больше ' . WorkspaceService::OWNED_LIMIT . ' пространств. Удалите ненужное или напишите нам.'];
        }
        if ($workspace === null) {
            $this->flash->invalid(['name' => $name], $errors);

            return Response::redirect('/workspaces/new');
        }
        $this->flash->toast('Пространство «' . $workspace->name . '» создано.');

        return Response::redirect('/w/' . $workspace->publicId);
    }
}
