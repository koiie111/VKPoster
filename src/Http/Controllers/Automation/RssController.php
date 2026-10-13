<?php

declare(strict_types=1);

namespace App\Http\Controllers\Automation;

use App\Domain\Automation\AutomationException;
use App\Domain\Automation\Queue\QueueRepository;
use App\Domain\Automation\Rss\FeedException;
use App\Domain\Automation\Rss\RssFeed;
use App\Domain\Automation\Rss\RssPoller;
use App\Domain\Automation\Rss\RssRepository;
use App\Domain\Automation\Rss\RssService;
use App\Domain\Billing\Entitlements;
use App\Domain\Channel\ChannelRepository;
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
 * RSS and Atom feeds: the list with the state of each, the form (with a "check the feed" button that shows the latest entries as
 * posts without making any), switching on and off, deleting.
 */
final class RssController
{
    /** @var array<int, string> minutes => label */
    private const INTERVALS = [15 => 'Каждые 15 минут', 30 => 'Каждые 30 минут', 60 => 'Раз в час', 180 => 'Раз в 3 часа', 360 => 'Раз в 6 часов', 1440 => 'Раз в сутки'];

    public function __construct(
        private readonly View $view,
        private readonly FormFlash $flash,
        private readonly AutomationHttp $http,
        private readonly RssService $service,
        private readonly RssRepository $repository,
        private readonly RssPoller $poller,
        private readonly ChannelRepository $channels,
        private readonly QueueRepository $queues,
        private readonly Entitlements $entitlements,
        private readonly Permissions $permissions,
    ) {
    }

    public function index(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $zone = new DateTimeZone($context->timezone);
        $rows = [];
        foreach ($this->repository->all($context) as $feed) {
            $rows[] = [
                'id' => $feed->publicId,
                'name' => $feed->name,
                'url' => $feed->url,
                'enabled' => $feed->enabled,
                'mode' => $feed->mode === RssFeed::MODE_QUEUE ? 'в очередь' : 'в черновики',
                'interval' => self::INTERVALS[$feed->intervalMinutes] ?? ($feed->intervalMinutes . ' мин.'),
                'last_checked' => $feed->lastCheckedAt === null ? null : RuDates::full($feed->lastCheckedAt->setTimezone($zone)),
                'error' => $feed->lastError,
                'entries' => $this->repository->entryCount($context, $feed),
            ];
        }

        return $this->view->response('workspace/automation/rss.twig', [
            'workspace' => $context,
            'feeds' => $rows,
            'can_manage' => $this->permissions->allows($context->role, 'posts.publish'),
            'has_feature' => $this->entitlements->hasFeature($context->workspaceId, 'crosspost'),
            'base' => AutomationHttp::base($context),
        ]);
    }

    public function form(Request $request, ?array $values = null, array $preview = [], ?string $previewError = null): Response
    {
        $context = WorkspaceRequest::context($request);
        $params = $request->attribute('route_params');
        $feed = is_array($params) && isset($params['feedId']) ? $this->feed($context, $request) : null;
        $selected = [];
        foreach ($feed->channelIds ?? [] as $id) {
            $channel = $this->channels->findById($context, $id);
            if ($channel !== null) {
                $selected[] = $channel->publicId;
            }
        }
        $queue = $feed?->queueId === null ? null : $this->queues->findById($context, $feed->queueId);

        return $this->view->response('workspace/automation/rss_form.twig', [
            'workspace' => $context,
            'feed' => $feed,
            'values' => $values,
            'selected' => $values !== null ? AutomationHttp::ids($request, 'channels') : $selected,
            'queue_id' => $queue?->publicId ?? '',
            'channels' => $this->http->channelChoices($context),
            'queues' => array_map(static fn ($q): array => ['id' => $q->publicId, 'name' => $q->name], $this->queues->all($context)),
            'intervals' => self::INTERVALS,
            'default_template' => RssFeed::DEFAULT_TEMPLATE,
            'preview' => $preview,
            'preview_error' => $previewError,
            'base' => AutomationHttp::base($context),
        ]);
    }

    /**
     * "Check the feed": fetch it and show the latest entries as posts would look. Nothing is saved.
     */
    public function preview(Request $request): Response
    {
        $url = AutomationHttp::text($request, 'url', 2000);
        $template = AutomationHttp::text($request, 'template', RssService::MAX_TEMPLATE);
        $preview = [];
        $error = null;
        try {
            if (preg_match('~^https?://[^\s]{4,1990}$~i', $url) !== 1) {
                throw new FeedException('Вставьте адрес ленты, который начинается с http:// или https://.');
            }
            $preview = $this->poller->preview($url, $template === '' ? RssFeed::DEFAULT_TEMPLATE : $template);
            if ($preview === []) {
                $error = 'Лента читается, но записей в ней пока нет.';
            }
        } catch (FeedException $e) {
            $error = $e->getMessage();
        }

        return $this->form($request, $request->all(), $preview, $error);
    }

    public function store(Request $request): Response
    {
        return $this->save($request, null);
    }

    public function update(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);

        return $this->save($request, $this->feed($context, $request));
    }

    public function enable(Request $request): Response
    {
        return $this->toggle($request, true);
    }

    public function disable(Request $request): Response
    {
        return $this->toggle($request, false);
    }

    public function delete(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $feed = $this->feed($context, $request);
        $back = AutomationHttp::base($context) . '/rss';
        try {
            $this->service->delete($context, $feed);
        } catch (AutomationException $e) {
            return $this->http->refuse($context, $e, $back);
        }
        $this->flash->toast('Лента «' . $feed->name . '» удалена. Созданные из неё посты остались.');

        return Response::redirect($back);
    }

    private function save(Request $request, ?RssFeed $feed): Response
    {
        $context = WorkspaceRequest::context($request);
        $base = AutomationHttp::base($context);
        $back = $base . '/rss/' . ($feed === null ? 'new' : $feed->publicId . '/edit');
        $interval = AutomationHttp::text($request, 'interval', 5);
        $queue = AutomationHttp::text($request, 'queue', 26);
        try {
            $name = AutomationHttp::text($request, 'name', 100);
            $url = AutomationHttp::text($request, 'url', 2000);
            $template = AutomationHttp::text($request, 'template', RssService::MAX_TEMPLATE);
            $mode = AutomationHttp::text($request, 'mode', 12);
            $minutes = ctype_digit($interval) ? (int) $interval : 0;
            $channels = AutomationHttp::ids($request, 'channels');
            if ($feed === null) {
                $this->service->create($context, $name, $url, $template, $mode, $queue === '' ? null : $queue, $minutes, $channels);
                $this->flash->toast('Лента подключена. Новые записи появятся в черновиках или в очереди; старые мы не трогаем.');
            } else {
                $this->service->update($context, $feed, $name, $url, $template, $mode, $queue === '' ? null : $queue, $minutes, $channels);
                $this->flash->toast('Лента сохранена.');
            }
        } catch (AutomationException $e) {
            return $this->http->refuse($context, $e, $back, $request->all());
        }

        return Response::redirect($base . '/rss');
    }

    private function toggle(Request $request, bool $enable): Response
    {
        $context = WorkspaceRequest::context($request);
        $feed = $this->feed($context, $request);
        $back = AutomationHttp::base($context) . '/rss';
        try {
            $this->service->setEnabled($context, $feed, $enable);
        } catch (AutomationException $e) {
            return $this->http->refuse($context, $e, $back);
        }
        $this->flash->toast($enable ? 'Лента снова читается.' : 'Лента остановлена.');

        return Response::redirect($back);
    }

    private function feed(WorkspaceContext $context, Request $request): RssFeed
    {
        return $this->repository->find($context, AutomationHttp::id($request, 'feedId')) ?? throw new HttpException(404, 'Not found');
    }
}
