<?php

declare(strict_types=1);

namespace App\Http\Controllers\Automation;

use App\Domain\Automation\AutomationException;
use App\Domain\Automation\Crosspost\CrosspostFilter;
use App\Domain\Automation\Crosspost\CrosspostRepository;
use App\Domain\Automation\Crosspost\CrosspostRule;
use App\Domain\Automation\Crosspost\CrosspostService;
use App\Domain\Automation\Crosspost\CrosspostTransform;
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
 * Cross-posting rules (Telegram → other networks): the list with the last result of each rule, the form, switching on and off, deleting.
 */
final class CrosspostController
{
    /** @var array<int, string> minutes => label */
    private const DELAYS = [0 => 'Сразу', 5 => 'Через 5 минут', 15 => 'Через 15 минут', 30 => 'Через 30 минут', 60 => 'Через час', 180 => 'Через 3 часа', 1440 => 'Через сутки'];

    public function __construct(
        private readonly View $view,
        private readonly FormFlash $flash,
        private readonly AutomationHttp $http,
        private readonly CrosspostService $service,
        private readonly CrosspostRepository $repository,
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
        foreach ($this->repository->all($context) as $rule) {
            $source = $this->channels->findById($context, $rule->sourceChannelId);
            $targets = [];
            foreach ($rule->targetIds as $id) {
                $channel = $this->channels->findById($context, $id);
                if ($channel !== null) {
                    $targets[] = $channel->displayName();
                }
            }
            $rows[] = [
                'id' => $rule->publicId,
                'name' => $rule->name,
                'source' => $source?->displayName() ?? 'Канал отключён',
                'targets' => $targets,
                'enabled' => $rule->enabled,
                'mode' => $rule->mode === CrosspostRule::MODE_DRAFT ? 'в черновики на согласование' : 'сразу в публикацию',
                'delay' => $rule->delayMinutes > 0 ? TriggerPanel::duration($rule->delayMinutes) : null,
                'created' => $rule->postsCreated,
                'last_run' => $rule->lastRunAt === null ? null : RuDates::full($rule->lastRunAt->setTimezone($zone)),
                'last_note' => $rule->lastNote,
            ];
        }
        $limit = $this->entitlements->limit($context->workspaceId, 'crosspost_rules');

        return $this->view->response('workspace/automation/crosspost.twig', [
            'workspace' => $context,
            'rules' => $rows,
            'sources' => $this->service->sources($context),
            'can_manage' => $this->permissions->allows($context->role, 'channels.manage'),
            'has_feature' => $this->entitlements->hasFeature($context->workspaceId, 'crosspost'),
            'limit' => $limit,
            'plan' => $this->entitlements->plan($context->workspaceId)->name,
            'base' => AutomationHttp::base($context),
        ]);
    }

    public function form(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $params = $request->attribute('route_params');
        $rule = is_array($params) && isset($params['ruleId']) ? $this->rule($context, $request) : null;
        $sources = array_map(static fn ($c): array => ['id' => $c->publicId, 'name' => $c->displayName()], $this->service->sources($context));
        $selected = [];
        foreach ($rule->targetIds ?? [] as $id) {
            $channel = $this->channels->findById($context, $id);
            if ($channel !== null) {
                $selected[] = $channel->publicId;
            }
        }
        $source = $rule === null ? null : $this->channels->findById($context, $rule->sourceChannelId);
        $links = $rule->transform->links ?? [];

        return $this->view->response('workspace/automation/crosspost_form.twig', [
            'workspace' => $context,
            'rule' => $rule,
            'source_id' => $source?->publicId ?? '',
            'sources' => $sources,
            'channels' => $this->http->channelChoices($context),
            'selected' => $selected,
            'delays' => self::DELAYS,
            'include' => implode(' ', array_map(static fn (string $t): string => '#' . $t, $rule->filter->includeTags ?? [])),
            'exclude' => implode(' ', array_map(static fn (string $t): string => '#' . $t, $rule->filter->excludeTags ?? [])),
            'signatures' => implode("\n", $rule->transform->signatures ?? []),
            'links' => [$links[0] ?? ['from' => '', 'to' => ''], $links[1] ?? ['from' => '', 'to' => ''], $links[2] ?? ['from' => '', 'to' => '']],
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

        return $this->save($request, $this->rule($context, $request));
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
        $rule = $this->rule($context, $request);
        $back = AutomationHttp::base($context) . '/crosspost';
        try {
            $this->service->delete($context, $rule);
        } catch (AutomationException $e) {
            return $this->http->refuse($context, $e, $back);
        }
        $this->flash->toast('Правило «' . $rule->name . '» удалено. Созданные им посты остались.');

        return Response::redirect($back);
    }

    private function save(Request $request, ?CrosspostRule $rule): Response
    {
        $context = WorkspaceRequest::context($request);
        $base = AutomationHttp::base($context);
        $back = $base . '/crosspost/' . ($rule === null ? 'new' : $rule->publicId . '/edit');
        $links = [];
        $rawLinks = $request->input('links');
        foreach (is_array($rawLinks) ? $rawLinks : [] as $pair) {
            if (is_array($pair)) {
                $links[] = ['from' => is_string($pair['from'] ?? null) ? $pair['from'] : '', 'to' => is_string($pair['to'] ?? null) ? $pair['to'] : ''];
            }
        }
        $filter = new CrosspostFilter(CrosspostFilter::tags($request->input('include_tags')), CrosspostFilter::tags($request->input('exclude_tags')), AutomationHttp::flag($request, 'skip_forwarded'));
        $transform = CrosspostTransform::fromArray(['signatures' => AutomationHttp::text($request, 'signatures', 1000), 'links' => $links, 'tail' => AutomationHttp::text($request, 'tail', CrosspostTransform::MAX_TAIL)]);
        $delay = AutomationHttp::text($request, 'delay', 6);
        $input = $request->all();
        try {
            $name = AutomationHttp::text($request, 'name', 100);
            $targets = AutomationHttp::ids($request, 'targets');
            $mode = AutomationHttp::text($request, 'mode', 12);
            $minutes = ctype_digit($delay) ? (int) $delay : 0;
            if ($rule === null) {
                $this->service->create($context, AutomationHttp::text($request, 'source', 26), $name, $targets, $filter, $transform, $minutes, $mode, AutomationHttp::flag($request, 'sync_edits'));
                $this->flash->toast('Правило создано. Новые посты канала будут переноситься по нему.');
            } else {
                $this->service->update($context, $rule, $name, $targets, $filter, $transform, $minutes, $mode, AutomationHttp::flag($request, 'sync_edits'));
                $this->flash->toast('Правило сохранено.');
            }
        } catch (AutomationException $e) {
            return $this->http->refuse($context, $e, $back, $input);
        }

        return Response::redirect($base . '/crosspost');
    }

    private function toggle(Request $request, bool $enable): Response
    {
        $context = WorkspaceRequest::context($request);
        $rule = $this->rule($context, $request);
        $back = AutomationHttp::base($context) . '/crosspost';
        try {
            $this->service->setEnabled($context, $rule, $enable);
        } catch (AutomationException $e) {
            return $this->http->refuse($context, $e, $back);
        }
        $this->flash->toast($enable ? 'Правило включено.' : 'Правило выключено.');

        return Response::redirect($back);
    }

    private function rule(WorkspaceContext $context, Request $request): CrosspostRule
    {
        return $this->repository->find($context, AutomationHttp::id($request, 'ruleId')) ?? throw new HttpException(404, 'Not found');
    }
}
