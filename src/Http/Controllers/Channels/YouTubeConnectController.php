<?php

declare(strict_types=1);

namespace App\Http\Controllers\Channels;

use App\Domain\Channel\ChannelException;
use App\Domain\Channel\YouTubeConnections;
use App\Domain\Workspace\Permissions;
use App\Domain\Workspace\WorkspaceContext;
use App\Domain\Workspace\WorkspaceRepository;
use App\Http\FormFlash;
use App\Http\WorkspaceRequest;
use App\Integrations\Social\Contracts\Platform;
use App\Integrations\Social\YouTube\YouTubeOAuth;
use App\Integrations\Social\PlatformRegistry;
use App\Kernel\Config;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;
use App\Support\Clock;
use Psr\Log\LoggerInterface;

/**
 * Connecting a YouTube account in two steps: the page with the explanation (and the button), and the way there and back through
 * Google OAuth. `/channels/connect/youtube/callback` has a fixed address because Google requires a registered redirect, so the workspace travels
 * in the session, and the person's membership and right to connect channels are checked again on the way back. The state lives in the
 * session only and is single use. A sign-in is one account, so the callback connects the channel straight away.
 */
final class YouTubeConnectController
{
    private const FLOW_KEY = 'channels.youtube.flow';
    private const TTL = 900;

    public function __construct(
        private readonly View $view,
        private readonly FormFlash $flash,
        private readonly YouTubeConnections $connections,
        private readonly PlatformRegistry $registry,
        private readonly WorkspaceRepository $workspaces,
        private readonly Permissions $permissions,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function show(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $this->requireEnabled();

        return $this->view->response('workspace/channels/connect_youtube.twig', [
            'workspace' => $context,
            'base' => '/w/' . $context->workspacePublicId . '/channels',
            'available' => $this->connections->available(),
            'redirect_uri' => $this->redirectUri(),
        ]);
    }

    /**
     * Send the person to YouTube. Only the session is touched (like signing in), so this is a plain link.
     */
    public function start(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $this->requireEnabled();
        $back = '/w/' . $context->workspacePublicId . '/channels/connect/youtube';
        if (!$this->connections->available()) {
            $this->flash->toast('Подключение YouTube пока не настроено.', 'error');

            return Response::redirect($back);
        }
        $begun = $this->connections->begin($this->redirectUri());
        $this->flash->session()->set(self::FLOW_KEY, [
            'state' => $begun['state'],
            'verifier' => $begun['verifier'],
            'workspace' => $context->workspacePublicId,
            'until' => $this->clock->now()->getTimestamp() + self::TTL,
        ]);

        return Response::redirectToTrusted($begun['url'], [YouTubeOAuth::AUTHORIZE_HOST]);
    }

    public function callback(Request $request): Response
    {
        $this->requireEnabled();
        $flow = $this->flash->session()->pull(self::FLOW_KEY);
        $state = $request->input('state');
        if (!is_array($flow) || !is_string($flow['state'] ?? null) || !is_string($flow['workspace'] ?? null) || !is_string($state) || $state === ''
            || !is_int($flow['until'] ?? null) || $flow['until'] < $this->clock->now()->getTimestamp() || !hash_equals($flow['state'], $state)) {
            $this->flash->toast('Ссылка устарела. Начните подключение YouTube заново.', 'error');

            return Response::redirect('/app');
        }
        $user = WorkspaceRequest::user($request);
        $workspace = $this->workspaces->findByPublicId($flow['workspace']);
        $membership = $workspace === null ? null : $this->workspaces->membership($workspace->id, $user->id);
        if ($workspace === null || $membership === null || !$this->permissions->allows($membership->role, 'channels.manage')) {
            throw new HttpException(404, 'Not found');
        }
        $context = WorkspaceContext::from($workspace, $membership);
        $base = '/w/' . $context->workspacePublicId . '/channels';

        $code = $request->input('code');
        if (is_string($request->input('error')) || !is_string($code) || $code === '') {
            $this->flash->toast('Вы не разрешили доступ YouTube, поэтому аккаунт не подключён. Попробуйте ещё раз и согласитесь на все права.', 'error');

            return Response::redirect($base . '/connect/youtube');
        }
        try {
            $channel = $this->connections->complete($context, $code, is_string($flow['verifier'] ?? null) ? $flow['verifier'] : '', $this->redirectUri());
        } catch (ChannelException $e) {
            $this->flash->refusal($e->getMessage(), $e->planLimit, $context->workspacePublicId);

            return Response::redirect($base . '/connect/youtube');
        }
        $this->logger->info('youtube.connected', ['workspace' => $context->workspacePublicId]);
        $this->flash->toast('Аккаунт «' . $channel->displayName() . '» подключён. Теперь в него можно планировать посты.');

        return Response::redirect($base);
    }

    private function redirectUri(): string
    {
        return rtrim($this->config->string('app.url'), '/') . '/channels/connect/youtube/callback';
    }

    private function requireEnabled(): void
    {
        if (!$this->registry->isEnabled(Platform::YouTube)) {
            throw new HttpException(404, 'Not found');
        }
    }
}
