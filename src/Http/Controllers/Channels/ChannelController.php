<?php

declare(strict_types=1);

namespace App\Http\Controllers\Channels;

use App\Domain\Channel\Channel;
use App\Domain\Channel\ChannelAvatars;
use App\Domain\Channel\ChannelException;
use App\Domain\Channel\ChannelRepository;
use App\Domain\Channel\ChannelService;
use App\Domain\Channel\ChannelStatus;
use App\Domain\Channel\ConnectCodes;
use App\Domain\Channel\SharedBot;
use App\Domain\Workspace\ChannelAccessRepository;
use App\Domain\Workspace\WorkspaceContext;
use App\Http\FormFlash;
use App\Http\WorkspaceRequest;
use App\Integrations\Social\Contracts\Platform;
use App\Integrations\Social\PlatformRegistry;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;

/**
 * The "Channels" section: the list, connecting a channel (Telegram through our bot with a one-time code, or through the
 * customer's own bot), and pausing, resuming, renaming, checking and disconnecting. Viewing is open to everyone who works
 * on posts (a restricted member only sees the channels assigned to them), changing needs `channels.manage`.
 */
final class ChannelController
{
    private const CODE_SESSION_KEY = 'channels.connect_code';

    public function __construct(
        private readonly View $view,
        private readonly FormFlash $flash,
        private readonly ChannelRepository $channels,
        private readonly ChannelAccessRepository $access,
        private readonly ChannelService $service,
        private readonly ChannelAvatars $avatars,
        private readonly ConnectCodes $codes,
        private readonly SharedBot $sharedBot,
        private readonly PlatformRegistry $registry,
    ) {
    }

    public function index(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $base = '/w/' . $context->workspacePublicId . '/channels';
        $rows = [];
        foreach ($this->channels->all($context, $this->access->allowed($context, $context->userId)) as $channel) {
            $rows[] = [
                'id' => $channel->publicId,
                'platform' => $channel->platform->value,
                'platform_label' => $channel->platform->label(),
                'name' => $channel->displayName(),
                'title' => $channel->title,
                'alias' => $channel->alias ?? '',
                'handle' => $channel->handle(),
                'status' => self::badge($channel->status),
                'is_paused' => $channel->status === ChannelStatus::Paused,
                'attention' => $channel->status->needsAttention(),
                'note' => $this->note($channel, $context),
                'avatar' => $channel->avatarKey === null ? null : $base . '/' . $channel->publicId . '/avatar',
                'own_bot' => $channel->mode->value === 'own_bot',
                'account' => $channel->mode->value === 'account',
            ];
        }

        return $this->view->response('workspace/channels/index.twig', [
            'workspace' => $context,
            'channels' => $rows,
            // Platforms that have a connect page (others join as their stages arrive).
            'platforms' => array_map(static fn (Platform $p): array => ['id' => $p->value, 'label' => $p->label(), 'icon' => $p->icon()], array_values(array_filter(
                $this->registry->enabled(),
                static fn (Platform $p): bool => in_array($p, [Platform::Telegram, Platform::Vk, Platform::Max, Platform::Fake], true),
            ))),
            'base' => $base,
        ]);
    }

    public function fakeForm(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $this->requireEnabled(Platform::Fake);

        return $this->view->response('workspace/channels/connect_fake.twig', ['workspace' => $context, 'base' => '/w/' . $context->workspacePublicId . '/channels']);
    }

    public function connectTelegram(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $this->requireEnabled(Platform::Telegram);
        $base = '/w/' . $context->workspacePublicId . '/channels';

        // The code is kept in the session (plain, short-lived) so that reloading the page does not lose it.
        $session = $this->flash->session();
        $pending = $session->get(self::CODE_SESSION_KEY);
        $code = null;
        if (is_array($pending) && is_string($pending['id'] ?? null) && is_string($pending['code'] ?? null) && is_int($pending['expires'] ?? null)) {
            $status = $this->codes->status($context, $pending['id']);
            if ($status !== null && $status['state'] !== 'expired' && $status['state'] !== 'connected') {
                $code = ['id' => $pending['id'], 'text' => ConnectCodes::pretty($pending['code']), 'plain' => $pending['code'], 'expires' => $pending['expires']];
            } else {
                $session->forget(self::CODE_SESSION_KEY);
            }
        }

        return $this->view->response('workspace/channels/connect_telegram.twig', [
            'workspace' => $context,
            'base' => $base,
            'code' => $code,
            'bot_configured' => $this->sharedBot->configured(),
            'bot_username' => $this->sharedBot->username(),
            'status_url' => $code === null ? null : $base . '/connect/telegram/status/' . $code['id'],
            'tab' => $request->input('tab') === 'own' ? 'own' : 'shared',
        ]);
    }

    public function issueCode(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $this->requireEnabled(Platform::Telegram);
        $back = '/w/' . $context->workspacePublicId . '/channels/connect/telegram';
        if (!$this->sharedBot->configured()) {
            $this->flash->toast('Бот сервиса пока не настроен. Подключите канал через свой бот.', 'error');

            return Response::redirect($back);
        }
        try {
            $this->service->assertRoom($context);
        } catch (ChannelException $e) {
            $this->flash->toast($e->getMessage(), 'error');

            return Response::redirect($back);
        }
        $issued = $this->codes->issue($context, Platform::Telegram);
        $this->flash->session()->set(self::CODE_SESSION_KEY, ['id' => $issued->publicId, 'code' => $issued->code, 'expires' => $issued->expiresAt->getTimestamp()]);

        return Response::redirect($back);
    }

    public function codeStatus(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $this->requireEnabled(Platform::Telegram);
        $status = $this->codes->status($context, $this->param($request, 'codeId')) ?? throw new HttpException(404, 'Not found');
        if ($status['state'] === 'connected' || $status['state'] === 'expired') {
            $this->flash->session()->forget(self::CODE_SESSION_KEY);
        }

        return Response::json($status)->withHeader('Cache-Control', 'no-store');
    }

    public function connectOwn(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $this->requireEnabled(Platform::Telegram);
        $back = '/w/' . $context->workspacePublicId . '/channels/connect/telegram?tab=own';
        $token = WorkspaceRequest::text($request->input('token'));
        $reference = mb_substr(WorkspaceRequest::text($request->input('reference')), 0, 200);
        try {
            $channel = $this->service->connectOwnTelegramBot($context, $token, $reference);
        } catch (ChannelException $e) {
            // The token is not sent back to the page: only the channel name the person typed.
            $this->flash->invalid(['reference' => $reference], ['form' => $e->getMessage()]);

            return Response::redirect($back);
        }
        $this->flash->toast('Канал «' . $channel->displayName() . '» подключён. Теперь в него можно планировать посты.');

        return Response::redirect('/w/' . $context->workspacePublicId . '/channels');
    }

    public function connectFake(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $this->requireEnabled(Platform::Fake);
        try {
            $channel = $this->service->connectFake($context, WorkspaceRequest::text($request->input('name')));
        } catch (ChannelException $e) {
            $this->flash->toast($e->getMessage(), 'error');

            return Response::redirect('/w/' . $context->workspacePublicId . '/channels');
        }
        $this->flash->toast('Тестовый канал «' . $channel->displayName() . '» подключён.');

        return Response::redirect('/w/' . $context->workspacePublicId . '/channels');
    }

    public function check(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $channel = $this->visible($request, $context);
        $checked = $this->service->checkNow($context, $channel);
        if ($checked->status === ChannelStatus::Active || $checked->status === ChannelStatus::Paused) {
            $this->flash->toast('С каналом «' . $checked->displayName() . '» всё в порядке.');
        } else {
            $this->flash->toast('Канал «' . $checked->displayName() . '» пока не работает: ' . ($checked->lastError ?? 'неизвестная причина'), 'error');
        }

        return Response::redirect('/w/' . $context->workspacePublicId . '/channels');
    }

    public function pause(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $channel = $this->visible($request, $context);
        $this->service->pause($context, $channel);
        $this->flash->toast('Канал «' . $channel->displayName() . '» на паузе. Посты в него не уйдут, пока вы не возобновите.');

        return Response::redirect('/w/' . $context->workspacePublicId . '/channels');
    }

    public function resume(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $channel = $this->visible($request, $context);
        $after = $this->service->resume($context, $channel);
        if ($after->status === ChannelStatus::Active) {
            $this->flash->toast('Канал «' . $after->displayName() . '» снова работает.');
        } else {
            $this->flash->toast('Канал «' . $after->displayName() . '» не удалось возобновить: ' . ($after->lastError ?? 'неизвестная причина'), 'error');
        }

        return Response::redirect('/w/' . $context->workspacePublicId . '/channels');
    }

    public function rename(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $channel = $this->visible($request, $context);
        $alias = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', WorkspaceRequest::text($request->input('name'))));
        if (mb_strlen($alias) > 100) {
            $this->flash->toast('Название — не длиннее 100 символов.', 'error');
        } else {
            $this->service->rename($context, $channel, $alias);
            $this->flash->toast($alias === '' ? 'Вернули название из соцсети.' : 'Название изменено.');
        }

        return Response::redirect('/w/' . $context->workspacePublicId . '/channels');
    }

    public function delete(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $channel = $this->visible($request, $context);
        $this->service->remove($context, $channel);
        $this->flash->toast('Канал «' . $channel->displayName() . '» отключён.');

        return Response::redirect('/w/' . $context->workspacePublicId . '/channels');
    }

    public function avatar(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $opened = $this->avatars->open($this->visible($request, $context)) ?? throw new HttpException(404, 'Not found');

        return Response::stream($opened['stream'], ['Content-Type' => $opened['mime'], 'Cache-Control' => 'private, max-age=3600', 'X-Content-Type-Options' => 'nosniff']);
    }

    /**
     * A channel of this workspace that the member may see; anything else is a 404, so ids cannot be probed.
     */
    private function visible(Request $request, WorkspaceContext $context): Channel
    {
        $channel = $this->channels->find($context, $this->param($request, 'channelId')) ?? throw new HttpException(404, 'Not found');
        $allowed = $this->access->allowed($context, $context->userId);
        if ($allowed !== null && !in_array($channel->id, $allowed, true)) {
            throw new HttpException(404, 'Not found');
        }

        return $channel;
    }

    private function requireEnabled(Platform $platform): void
    {
        if (!$this->registry->isEnabled($platform)) {
            throw new HttpException(404, 'Not found');
        }
    }

    private function param(Request $request, string $name): string
    {
        $params = $request->attribute('route_params');

        return is_array($params) && is_string($params[$name] ?? null) ? $params[$name] : '';
    }

    /**
     * Key for `status_badge`: the shared component names the states "connected" and "needs_reauth".
     */
    private static function badge(ChannelStatus $status): string
    {
        return match ($status) {
            ChannelStatus::Active => 'connected',
            ChannelStatus::Paused => 'paused',
            ChannelStatus::Error => 'error',
            ChannelStatus::Revoked => 'needs_reauth',
        };
    }

    private function note(Channel $channel, WorkspaceContext $context): string
    {
        if ($channel->status->needsAttention() && $channel->lastError !== null) {
            return $channel->lastError;
        }
        if ($channel->status === ChannelStatus::Paused) {
            return 'Посты в этот канал не отправляются.';
        }
        $rights = $channel->rights();
        $missing = [];
        foreach (['delete' => 'удалять посты', 'pin' => 'закреплять'] as $key => $label) {
            if ($rights !== [] && ($rights[$key] ?? false) === false) {
                $missing[] = $label;
            }
        }
        $checked = $channel->lastHealthAt === null ? '' : 'Проверен ' . $channel->lastHealthAt->setTimezone(new \DateTimeZone($context->timezone))->format('d.m.Y H:i') . '. ';

        return $checked . ($missing === [] ? '' : ($channel->platform === Platform::Vk ? 'Аккаунту' : 'Боту') . ' не хватает права: ' . implode(', ', $missing) . '.');
    }
}
