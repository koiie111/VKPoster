<?php

declare(strict_types=1);

namespace App\Http\Controllers\Link;

use App\Domain\Audit\AuditLog;
use App\Domain\Channel\ChannelRepository;
use App\Domain\Link\LinkException;
use App\Domain\Link\LinkSettings;
use App\Domain\Link\ShortLinks;
use App\Domain\Link\UtmRule;
use App\Http\FormFlash;
use App\Http\WorkspaceRequest;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;

/**
 * Workspace page "Ссылки": the UTM rule of the workspace and of each channel, switching short links on, a form for a one-off short
 * link, and the list of the workspace's short links with their clicks. For owners and administrators (`workspace.settings`).
 */
final class LinkSettingsController
{
    public function __construct(
        private readonly View $view,
        private readonly LinkSettings $settings,
        private readonly ShortLinks $links,
        private readonly ChannelRepository $channels,
        private readonly AuditLog $audit,
        private readonly FormFlash $flash,
        private readonly int $manualPerDay,
    ) {
    }

    public function show(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $rows = [];
        foreach ($this->channels->all($context) as $channel) {
            $own = $this->settings->channelRule($context->workspaceId, $channel->id);
            $rows[] = ['id' => $channel->publicId, 'name' => $channel->displayName(), 'platform' => $channel->platform->value, 'rule' => $own ?? new UtmRule(), 'mode' => $own === null ? 'inherit' : ($own->enabled ? 'custom' : 'off')];
        }
        $links = [];
        foreach ($this->links->forWorkspace($context->workspaceId, 100) as $link) {
            $links[] = [
                'url' => $this->links->urlFor((string) $link['code']),
                'target' => (string) $link['target_url'],
                'platform' => (string) $link['platform'],
                'clicks' => (int) $link['clicks'],
                'human' => (int) $link['human_clicks'],
                'last' => $link['last_click_at'],
                'created' => $link['created_at'],
                'disabled' => $link['disabled_at'] !== null,
                'id' => (string) $link['public_id'],
            ];
        }

        return $this->view->response('workspace/links/settings.twig', [
            'workspace' => $context,
            'base' => '/w/' . $context->workspacePublicId,
            'rule' => $this->settings->workspaceRule($context->workspaceId),
            'shorten' => $this->settings->shortens($context->workspaceId),
            'channel_rules' => $rows,
            'links' => $links,
            'placeholders' => UtmRule::PLACEHOLDERS,
            'sample' => $this->links->sampleUrl(),
        ]);
    }

    public function saveWorkspace(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $rule = $this->ruleFrom($request);
        $problems = $rule->problems();
        if ($problems !== []) {
            $this->flash->toast(implode(' ', $problems), 'error');

            return Response::redirect('/w/' . $context->workspacePublicId . '/settings/links');
        }
        $shorten = $this->flag($request->input('shorten'));
        $this->settings->saveWorkspace($context->workspaceId, $rule, $shorten);
        $this->audit->record('links.settings_updated', $context->userId, 'workspace', $context->workspacePublicId, ['utm' => $rule->enabled ? '1' : '0', 'shorten' => $shorten ? '1' : '0'], $context->workspaceId);
        $this->flash->toast('Настройки ссылок сохранены. Они работают для постов, которые ещё не вышли.');

        return Response::redirect('/w/' . $context->workspacePublicId . '/settings/links');
    }

    /** `mode`: `inherit` (follow the workspace), `off` (no tags for this channel) or `custom` (the form's own rule). */
    public function saveChannel(Request $request, string $channelId): Response
    {
        $context = WorkspaceRequest::context($request);
        $channel = $this->channels->find($context, $channelId) ?? throw new HttpException(404, 'Not found');
        $mode = WorkspaceRequest::text($request->input('mode'));
        $back = '/w/' . $context->workspacePublicId . '/settings/links#ch-' . $channel->publicId;
        if ($mode === 'custom') {
            $rule = $this->ruleFrom($request, true);
            $problems = $rule->problems();
            if ($problems !== []) {
                $this->flash->toast(implode(' ', $problems), 'error');

                return Response::redirect($back);
            }
            $this->settings->saveChannelRule($context->workspaceId, $channel->id, $rule);
        } elseif ($mode === 'off') {
            $this->settings->saveChannelRule($context->workspaceId, $channel->id, new UtmRule(false));
        } else {
            $mode = 'inherit';
            $this->settings->saveChannelRule($context->workspaceId, $channel->id, null);
        }
        $this->audit->record('links.channel_rule_updated', $context->userId, 'channel', $channel->publicId, ['mode' => $mode], $context->workspaceId);
        $this->flash->toast('Метки для канала «' . $channel->displayName() . '» сохранены.');

        return Response::redirect($back);
    }

    public function create(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $back = '/w/' . $context->workspacePublicId . '/settings/links#short';
        try {
            $code = $this->links->createManual($context->workspaceId, $context->userId, WorkspaceRequest::text($request->input('url')), $this->manualPerDay);
        } catch (LinkException $e) {
            $this->flash->toast($e->getMessage(), 'error');

            return Response::redirect($back);
        }
        $this->audit->record('links.short_created', $context->userId, 'workspace', $context->workspacePublicId, [], $context->workspaceId);
        $this->flash->toast('Готово: ' . $this->links->urlFor($code));

        return Response::redirect($back);
    }

    public function toggle(Request $request, string $linkId): Response
    {
        $context = WorkspaceRequest::context($request);
        $found = $this->links->findInWorkspace($context->workspaceId, $linkId) ?? throw new HttpException(404, 'Not found');
        // A link that staff switched off for abuse can not be switched on again by its owner.
        $off = $found['disabled_at'] === null;
        if (!$off && $found['disabled_reason'] !== null) {
            $this->flash->toast('Эту ссылку отключила поддержка. Напишите нам, если это ошибка.', 'error');

            return Response::redirect('/w/' . $context->workspacePublicId . '/settings/links#short');
        }
        $this->links->setDisabled((int) $found['id'], $off);
        $this->audit->record($off ? 'links.short_disabled' : 'links.short_enabled', $context->userId, 'workspace', $context->workspacePublicId, [], $context->workspaceId);
        $this->flash->toast($off ? 'Ссылка отключена: по ней больше нельзя перейти.' : 'Ссылка снова работает.');

        return Response::redirect('/w/' . $context->workspacePublicId . '/settings/links#short');
    }

    private function ruleFrom(Request $request, bool $forceOn = false): UtmRule
    {
        $data = ['enabled' => $forceOn || $this->flag($request->input('enabled'))];
        foreach (['source', 'medium', 'campaign', 'content', 'term'] as $key) {
            $data[$key] = WorkspaceRequest::text($request->input($key));
        }

        return UtmRule::fromArray($data);
    }

    private function flag(mixed $value): bool
    {
        return $value === '1' || $value === 'on' || $value === true;
    }
}
