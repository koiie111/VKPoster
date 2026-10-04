<?php

declare(strict_types=1);

namespace App\Http\Controllers\Channels;

use App\Domain\Channel\ChannelException;
use App\Domain\Channel\ChannelService;
use App\Domain\Channel\ConnectCodes;
use App\Domain\Channel\SharedBot;
use App\Http\FormFlash;
use App\Http\WorkspaceRequest;
use App\Integrations\Social\Contracts\Platform;
use App\Integrations\Social\PlatformRegistry;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;

/**
 * Connecting a MAX channel: through our shared bot with a one-time code (written as `/connect CODE` in the channel), or through the
 * customer's own bot (token and channel link). MAX has no way to list the chats a bot belongs to, so there is no "pick from a list" step.
 * All routes need `channels.manage`; the whole controller is a 404 while MAX is switched off.
 */
final class MaxConnectController
{
    private const CODE_SESSION_KEY = 'channels.connect_code.max';

    public function __construct(
        private readonly View $view,
        private readonly FormFlash $flash,
        private readonly ChannelService $service,
        private readonly ConnectCodes $codes,
        private readonly SharedBot $sharedBot,
        private readonly PlatformRegistry $registry,
    ) {
    }

    public function show(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $this->requireEnabled();
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

        return $this->view->response('workspace/channels/connect_max.twig', [
            'workspace' => $context,
            'base' => $base,
            'code' => $code,
            'bot_configured' => $this->sharedBot->configured(Platform::Max),
            'bot_username' => $this->sharedBot->username(Platform::Max),
            'status_url' => $code === null ? null : $base . '/connect/max/status/' . $code['id'],
            'tab' => $request->input('tab') === 'own' ? 'own' : 'shared',
        ]);
    }

    public function issueCode(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $this->requireEnabled();
        $back = '/w/' . $context->workspacePublicId . '/channels/connect/max';
        if (!$this->sharedBot->configured(Platform::Max)) {
            $this->flash->toast('Бот сервиса в MAX пока не настроен. Подключите канал через свой бот.', 'error');

            return Response::redirect($back);
        }
        try {
            $this->service->assertRoom($context);
        } catch (ChannelException $e) {
            $this->flash->refusal($e->getMessage(), $e->planLimit, $context->workspacePublicId);

            return Response::redirect($back);
        }
        $issued = $this->codes->issue($context, Platform::Max);
        $this->flash->session()->set(self::CODE_SESSION_KEY, ['id' => $issued->publicId, 'code' => $issued->code, 'expires' => $issued->expiresAt->getTimestamp()]);

        return Response::redirect($back);
    }

    public function codeStatus(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $this->requireEnabled();
        $params = $request->attribute('route_params');
        $id = is_array($params) && is_string($params['codeId'] ?? null) ? $params['codeId'] : '';
        $status = $this->codes->status($context, $id) ?? throw new HttpException(404, 'Not found');
        if ($status['state'] === 'connected' || $status['state'] === 'expired') {
            $this->flash->session()->forget(self::CODE_SESSION_KEY);
        }

        return Response::json($status)->withHeader('Cache-Control', 'no-store');
    }

    public function connectOwn(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $this->requireEnabled();
        $back = '/w/' . $context->workspacePublicId . '/channels/connect/max?tab=own';
        $token = WorkspaceRequest::text($request->input('token'));
        $reference = mb_substr(WorkspaceRequest::text($request->input('reference')), 0, 200);
        try {
            $channel = $this->service->connectOwnMaxBot($context, $token, $reference);
        } catch (ChannelException $e) {
            // The token is not sent back to the page: only the channel the person typed.
            if ($e->planLimit) {
                $this->flash->refusal($e->getMessage(), true, $context->workspacePublicId);
            } else {
                $this->flash->invalid(['reference' => $reference], ['form' => $e->getMessage()]);
            }

            return Response::redirect($back);
        }
        $this->flash->toast('Канал «' . $channel->displayName() . '» подключён. Теперь в него можно планировать посты.');

        return Response::redirect('/w/' . $context->workspacePublicId . '/channels');
    }

    private function requireEnabled(): void
    {
        if (!$this->registry->isEnabled(Platform::Max)) {
            throw new HttpException(404, 'Not found');
        }
    }
}
