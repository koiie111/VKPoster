<?php

declare(strict_types=1);

namespace App\Http\Controllers\Channels;

use App\Domain\Channel\ChannelException;
use App\Domain\Channel\InstagramConnections;
use App\Domain\Workspace\Permissions;
use App\Domain\Workspace\WorkspaceContext;
use App\Domain\Workspace\WorkspaceRepository;
use App\Http\FormFlash;
use App\Http\WorkspaceRequest;
use App\Integrations\Social\Contracts\Platform;
use App\Integrations\Social\Instagram\InstagramOAuth;
use App\Integrations\Social\PlatformRegistry;
use App\Kernel\Config;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;
use App\Support\Clock;
use Psr\Log\LoggerInterface;

/**
 * Connecting an Instagram account in two steps: the page with the explanation (and the button), and the way there and back through
 * Instagram Login. `/channels/connect/instagram/callback` has a fixed address because Meta wants it registered, so the workspace travels
 * in the session, and the person's membership and right to connect channels are checked again on the way back. The state lives in the
 * session only and is single use. A sign-in is one account, so the callback connects the channel straight away.
 */
final class InstagramConnectController
{
    private const FLOW_KEY = 'channels.instagram.flow';
    private const TTL = 900;

    public function __construct(
        private readonly View $view,
        private readonly FormFlash $flash,
        private readonly InstagramConnections $connections,
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

        return $this->view->response('workspace/channels/connect_instagram.twig', [
            'workspace' => $context,
            'base' => '/w/' . $context->workspacePublicId . '/channels',
            'available' => $this->connections->available(),
            'redirect_uri' => $this->redirectUri(),
        ]);
    }

    /**
     * Send the person to Instagram. Only the session is touched (like signing in), so this is a plain link.
     */
    public function start(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $this->requireEnabled();
        $back = '/w/' . $context->workspacePublicId . '/channels/connect/instagram';
        if (!$this->connections->available()) {
            $this->flash->toast('Подключение Instagram пока не настроено.', 'error');

            return Response::redirect($back);
        }
        $begun = $this->connections->begin($this->redirectUri());
        $this->flash->session()->set(self::FLOW_KEY, [
            'state' => $begun['state'],
            'workspace' => $context->workspacePublicId,
            'until' => $this->clock->now()->getTimestamp() + self::TTL,
        ]);

        return Response::redirectToTrusted($begun['url'], [InstagramOAuth::AUTHORIZE_HOST]);
    }

    public function callback(Request $request): Response
    {
        $this->requireEnabled();
        $flow = $this->flash->session()->pull(self::FLOW_KEY);
        $state = $request->input('state');
        if (!is_array($flow) || !is_string($flow['state'] ?? null) || !is_string($flow['workspace'] ?? null) || !is_string($state) || $state === ''
            || !is_int($flow['until'] ?? null) || $flow['until'] < $this->clock->now()->getTimestamp() || !hash_equals($flow['state'], $state)) {
            $this->flash->toast('Ссылка устарела. Начните подключение Instagram заново.', 'error');

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
            $this->flash->toast('Вы не разрешили доступ Instagram, поэтому аккаунт не подключён. Попробуйте ещё раз и согласитесь на все права.', 'error');

            return Response::redirect($base . '/connect/instagram');
        }
        try {
            $channel = $this->connections->complete($context, $code, $this->redirectUri());
        } catch (ChannelException $e) {
            $this->flash->refusal($e->getMessage(), $e->planLimit, $context->workspacePublicId);

            return Response::redirect($base . '/connect/instagram');
        }
        $this->logger->info('instagram.connected', ['workspace' => $context->workspacePublicId]);
        $this->flash->toast('Аккаунт «' . $channel->displayName() . '» подключён. Теперь в него можно планировать посты.');

        return Response::redirect($base);
    }

    private function redirectUri(): string
    {
        return rtrim($this->config->string('app.url'), '/') . '/channels/connect/instagram/callback';
    }

    private function requireEnabled(): void
    {
        if (!$this->registry->isEnabled(Platform::Instagram)) {
            throw new HttpException(404, 'Not found');
        }
    }
}
