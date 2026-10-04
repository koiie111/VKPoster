<?php

declare(strict_types=1);

namespace App\Http\Controllers\Posts;

use App\Domain\Channel\ChannelRepository;
use App\Domain\Post\CalendarItem;
use App\Domain\Post\CalendarRepository;
use App\Domain\Post\PostRepository;
use App\Domain\Post\PostService;
use App\Domain\Post\PublicationStatus;
use App\Domain\Post\ScheduleTime;
use App\Domain\Workspace\MemberRepository;
use App\Domain\Workspace\Permissions;
use App\Domain\Workspace\WorkspaceContext;
use App\Http\WorkspaceRequest;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;
use App\Support\Clock;
use App\Support\RuDates;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The calendar: month, week and list, filtered by channel, state and author. Everything is rendered on the server in the
 * workspace's time zone; the browser only adds drag and drop (`calendar.js`), which calls the move endpoint of `PostController`.
 */
final class CalendarController
{
    private const VIEWS = ['month', 'week', 'list'];

    public function __construct(
        private readonly View $view,
        private readonly CalendarRepository $calendar,
        private readonly ChannelRepository $channels,
        private readonly MemberRepository $members,
        private readonly PostRepository $posts,
        private readonly PostService $service,
        private readonly Permissions $permissions,
        private readonly Clock $clock,
    ) {
    }

    public function show(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $zone = new DateTimeZone($context->timezone);
        $utc = new DateTimeZone('UTC');
        $today = $this->clock->now()->setTimezone($zone)->setTime(0, 0);
        $view = WorkspaceRequest::text($request->input('view'));
        $view = in_array($view, self::VIEWS, true) ? $view : 'month';
        $anchor = $this->parseDay(WorkspaceRequest::text($request->input('date')), $zone) ?? $today;

        // Filters
        $allowed = $this->service->allowedChannels($context);
        $channelFilter = null;
        $channelRaw = WorkspaceRequest::text($request->input('channel'));
        if ($channelRaw !== '') {
            $found = $this->channels->find($context, $channelRaw);
            $channelFilter = $found !== null && ($allowed === null || in_array($found->id, $allowed, true)) ? $found : null;
        }
        $authorFilter = null;
        $authorRaw = WorkspaceRequest::text($request->input('author'));
        if ($authorRaw !== '') {
            $authorFilter = $this->members->find($context, $authorRaw);
        }
        $stateInput = $request->input('state');
        $raw = array_values(array_filter(is_array($stateInput) ? $stateInput : [], 'is_string'));
        $states = array_values(array_unique(array_filter($raw, static fn (string $s): bool => isset(CalendarRepository::STATE_GROUPS[$s]))));
        $wantDrafts = $raw === [] || in_array('draft', $raw, true);

        // Period
        [$from, $to, $title, $prev, $next] = match ($view) {
            'week' => $this->week($anchor),
            default => $this->month($anchor),
        };
        $items = $raw !== [] && $states === [] ? [] : $this->calendar->items(
            $context,
            $from->setTimezone($utc),
            $to->setTimezone($utc),
            $channelFilter?->id,
            $states,
            $authorFilter?->userId,
            $allowed,
        );

        $base = '/w/' . $context->workspacePublicId;
        $query = array_filter([
            'view' => $view === 'month' ? null : $view,
            'channel' => $channelFilter?->publicId,
            'author' => $authorFilter?->publicId,
            'state' => $raw === [] ? null : $raw,
        ], static fn (mixed $v): bool => $v !== null);
        $link = static fn (DateTimeImmutable $date): string => $base . '/calendar?' . http_build_query($query + ['date' => $date->format('Y-m-d')]);
        $canMove = $this->canPlan($context);
        $byDay = $this->groupByDay($items, $zone, $base, $canMove);

        $data = [
            'workspace' => $context,
            'view' => $view,
            'title' => $title,
            'prev_url' => $link($prev),
            'next_url' => $link($next),
            'today_url' => $base . '/calendar?' . http_build_query($query),
            'view_urls' => [
                'month' => $base . '/calendar?' . http_build_query(array_diff_key($query, ['view' => 1]) + ['date' => $anchor->format('Y-m-d')]),
                'week' => $base . '/calendar?' . http_build_query(['view' => 'week'] + array_diff_key($query, ['view' => 1]) + ['date' => $anchor->format('Y-m-d')]),
                'list' => $base . '/calendar?' . http_build_query(['view' => 'list'] + array_diff_key($query, ['view' => 1]) + ['date' => $anchor->format('Y-m-d')]),
            ],
            'timezone_label' => ScheduleTime::label($context->timezone, $this->clock->now()),
            'timezone' => $context->timezone,
            'base' => $base,
            'can_move' => $canMove,
            'can_create' => $this->canDraft($context),
            'filters' => [
                'channel' => $channelFilter->publicId ?? '',
                'author' => $authorFilter->publicId ?? '',
                'state' => $raw,
                'active' => $channelFilter !== null || $authorFilter !== null || $raw !== [],
            ],
            'channel_options' => $this->channelOptions($context, $allowed),
            'author_options' => $this->authorOptions($context),
            'is_empty' => $items === [],
        ];
        if ($view === 'month') {
            $data['weeks'] = $this->monthCells($anchor, $today, $byDay);
        } elseif ($view === 'week') {
            $data['days'] = $this->weekCells($anchor, $today, $byDay);
        } else {
            $data['groups'] = $this->groups($byDay, $zone);
            $data['drafts'] = $wantDrafts ? $this->drafts($context, $allowed, $base) : [];
            $data['is_empty'] = $items === [] && $data['drafts'] === [];
        }
        $data['new_url'] = $base . '/posts/new' . ($view === 'list' ? '' : '?date=' . $anchor->format('Y-m-d'));

        return $this->view->response('workspace/posts/calendar.twig', $data);
    }

    private function parseDay(string $value, DateTimeZone $zone): ?DateTimeImmutable
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone);

        return $day === false ? null : $day;
    }

    /**
     * @return array{DateTimeImmutable, DateTimeImmutable, string, DateTimeImmutable, DateTimeImmutable} from, to, title, previous anchor, next anchor
     */
    private function month(DateTimeImmutable $anchor): array
    {
        $first = $anchor->modify('first day of this month');
        $start = $first->modify('-' . ((int) $first->format('N') - 1) . ' days');

        return [$start, $start->modify('+42 days'), RuDates::monthYear($first), $first->modify('-1 month'), $first->modify('+1 month')];
    }

    /**
     * @return array{DateTimeImmutable, DateTimeImmutable, string, DateTimeImmutable, DateTimeImmutable}
     */
    private function week(DateTimeImmutable $anchor): array
    {
        $start = $anchor->modify('-' . ((int) $anchor->format('N') - 1) . ' days');
        $end = $start->modify('+6 days');
        $title = RuDates::dayMonth($start) . ' — ' . RuDates::dayMonth($end) . ' ' . $end->format('Y');

        return [$start, $start->modify('+7 days'), $title, $start->modify('-7 days'), $start->modify('+7 days')];
    }

    /**
     * @param list<CalendarItem> $items
     * @return array<string, list<array<string, mixed>>> local date => entries
     */
    private function groupByDay(array $items, DateTimeZone $zone, string $base, bool $canMove): array
    {
        $byDay = [];
        foreach ($items as $item) {
            $local = $item->at->setTimezone($zone);
            $byDay[$local->format('Y-m-d')][] = [
                'platform' => $item->platform->badgeKey(),
                'time' => $local->format('H:i'),
                'title' => $item->title,
                'status' => $item->status->badge(),
                'channel' => $item->channelName,
                'author' => $item->authorName,
                'href' => $base . '/posts/' . $item->postId,
                'post_id' => $item->postId,
                'draggable' => $canMove && $item->status === PublicationStatus::Queued,
                'failed' => in_array($item->status, [PublicationStatus::Failed, PublicationStatus::Unknown], true),
                'label' => $local->format('H:i') . ', ' . $item->channelName . ': ' . $item->title . ' (' . $item->status->label() . ')',
            ];
        }

        return $byDay;
    }

    /**
     * @param array<string, list<array<string, mixed>>> $byDay
     * @return list<list<array<string, mixed>>>
     */
    private function monthCells(DateTimeImmutable $anchor, DateTimeImmutable $today, array $byDay): array
    {
        $first = $anchor->modify('first day of this month');
        $day = $first->modify('-' . ((int) $first->format('N') - 1) . ' days');
        $weeks = [];
        for ($w = 0; $w < 6; ++$w) {
            $week = [];
            for ($d = 0; $d < 7; ++$d) {
                $key = $day->format('Y-m-d');
                $week[] = ['num' => (int) $day->format('j'), 'date' => $key, 'other' => $day->format('m') !== $first->format('m'), 'today' => $day->format('Y-m-d') === $today->format('Y-m-d'), 'items' => $byDay[$key] ?? []];
                $day = $day->modify('+1 day');
            }
            if ($w >= 4 && $week[0]['other']) {
                break; // the sixth row is only needed when the month spills into it
            }
            $weeks[] = $week;
        }

        return $weeks;
    }

    /**
     * @param array<string, list<array<string, mixed>>> $byDay
     * @return list<array<string, mixed>>
     */
    private function weekCells(DateTimeImmutable $anchor, DateTimeImmutable $today, array $byDay): array
    {
        $day = $anchor->modify('-' . ((int) $anchor->format('N') - 1) . ' days');
        $days = [];
        for ($i = 0; $i < 7; ++$i) {
            $key = $day->format('Y-m-d');
            $days[] = ['label' => RuDates::weekdayShort($day), 'num' => (int) $day->format('j'), 'date' => $key, 'today' => $key === $today->format('Y-m-d'), 'items' => $byDay[$key] ?? []];
            $day = $day->modify('+1 day');
        }

        return $days;
    }

    /**
     * @param array<string, list<array<string, mixed>>> $byDay
     * @return list<array{label: string, items: list<array<string, mixed>>}>
     */
    private function groups(array $byDay, DateTimeZone $zone): array
    {
        ksort($byDay);
        $groups = [];
        foreach ($byDay as $date => $items) {
            $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $zone);
            if ($day !== false) {
                $groups[] = ['label' => ucfirst(RuDates::weekdayDay($day)), 'items' => $items];
            }
        }

        return $groups;
    }

    /**
     * @param list<int>|null $allowed
     * @return list<array<string, mixed>>
     */
    private function drafts(WorkspaceContext $context, ?array $allowed, string $base): array
    {
        $result = [];
        foreach ($this->posts->drafts($context, $allowed, 30) as $post) {
            $result[] = ['title' => $post->title(70), 'href' => $base . '/posts/' . $post->publicId, 'time' => 'Черновик', 'platform' => 'vk', 'status' => 'draft', 'post_id' => $post->publicId, 'draggable' => false, 'failed' => false, 'channel' => '', 'author' => null, 'label' => 'Черновик: ' . $post->title(70)];
        }

        return $result;
    }

    /**
     * @param list<int>|null $allowed
     * @return array<string, string>
     */
    private function channelOptions(WorkspaceContext $context, ?array $allowed): array
    {
        $options = ['' => 'Все каналы'];
        foreach ($this->channels->all($context, $allowed) as $channel) {
            $options[$channel->publicId] = $channel->displayName() . ' · ' . $channel->platform->label();
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private function authorOptions(WorkspaceContext $context): array
    {
        $options = ['' => 'Все авторы'];
        foreach ($this->members->all($context) as $member) {
            $options[$member->publicId] = $member->name;
        }

        return $options;
    }

    private function canPlan(WorkspaceContext $context): bool
    {
        return $this->allows($context, 'posts.publish');
    }

    private function canDraft(WorkspaceContext $context): bool
    {
        return $this->allows($context, 'posts.draft');
    }

    private function allows(WorkspaceContext $context, string $permission): bool
    {
        return $this->permissions->allows($context->role, $permission);
    }
}
