<?php

declare(strict_types=1);

namespace App\Http\Controllers\Channels;

use App\Domain\Channel\ChannelException;
use App\Domain\Channel\VkConnections;
use App\Domain\Workspace\Permissions;
use App\Domain\Workspace\WorkspaceContext;
use App\Domain\Workspace\WorkspaceRepository;
use App\Http\FormFlash;
use App\Http\WorkspaceRequest;
use App\Integrations\Social\Contracts\Platform;
use App\Integrations\Social\PlatformRegistry;
use App\Integrations\Social\Vk\VkOAuth;
use App\Kernel\Config;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;
use App\Support\Clock;
use Psr\Log\LoggerInterface;

/**
 * Connecting VK communities in three steps: the page with the explanation (and the button), the way there and back through VK ID
 * (`/channels/connect/vk/callback` has a fixed address because VK wants it registered, so the workspace travels in the session, and the
 * person's membership and right to connect channels are checked again on the way back), and choosing the communities.
 * The state and the PKCE verifier live in the session only and are single use.
 */
final class VkConnectController
{
    private const FLOW_KEY = 'channels.vk.flow';
    private const PENDING_KEY = 'channels.vk.pending';
    private const TTL = 900;

    public function __construct(
        private readonly View $view,
        private readonly FormFlash $flash,
        private readonly VkConnections $connections,
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

        return $this->view->response('workspace/channels/connect_vk.twig', [
            'workspace' => $context,
            'base' => '/w/' . $context->workspacePublicId . '/channels',
            'available' => $this->connections->available(),
            'redirect_uri' => $this->redirectUri(),
        ]);
    }

    /**
     * Send the person to VK ID. Only the session is touched (like signing in), so this is a plain link.
     */
    public function start(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $this->requireEnabled();
        $back = '/w/' . $context->workspacePublicId . '/channels/connect/vk';
        if (!$this->connections->available()) {
            $this->flash->toast('Подключение ВКонтакте пока не настроено.', 'error');

            return Response::redirect($back);
        }
        $begun = $this->connections->begin($this->redirectUri());
        $this->flash->session()->set(self::FLOW_KEY, [
            'state' => $begun['state'],
            'verifier' => $begun['verifier'],
            'workspace' => $context->workspacePublicId,
            'until' => $this->clock->now()->getTimestamp() + self::TTL,
        ]);

        return Response::redirectToTrusted($begun['url'], [VkOAuth::AUTHORIZE_HOST]);
    }

    public function callback(Request $request): Response
    {
        $this->requireEnabled();
        $session = $this->flash->session();
        $flow = $session->pull(self::FLOW_KEY);
        $state = $request->input('state');
        if (!is_array($flow) || !is_string($flow['state'] ?? null) || !is_string($flow['workspace'] ?? null) || !is_string($state) || $state === ''
            || !is_int($flow['until'] ?? null) || $flow['until'] < $this->clock->now()->getTimestamp() || !hash_equals($flow['state'], $state)) {
            $this->flash->toast('Ссылка устарела. Начните подключение ВКонтакте заново.', 'error');

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
        $deviceId = $request->input('device_id');
        if (is_string($request->input('error')) || !is_string($code) || $code === '' || !is_string($deviceId) || $deviceId === '') {
            $this->flash->toast('Вы не разрешили доступ ВКонтакте, поэтому сообщество не подключено. Попробуйте ещё раз и согласитесь на все права.', 'error');

            return Response::redirect($base . '/connect/vk');
        }
        try {
            $credential = $this->connections->complete($context, $code, is_string($flow['verifier'] ?? null) ? $flow['verifier'] : '', $this->redirectUri(), $deviceId, $state);
        } catch (ChannelException $e) {
            $this->flash->toast($e->getMessage(), 'error');

            return Response::redirect($base . '/connect/vk');
        }
        $session->set(self::PENDING_KEY, ['credential' => $credential, 'workspace' => $context->workspacePublicId]);

        return Response::redirect($base . '/connect/vk/choose');
    }

    public function choose(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $this->requireEnabled();
        $base = '/w/' . $context->workspacePublicId . '/channels';
        $credential = $this->pending($context);
        if ($credential === null) {
            $this->flash->toast('Сначала войдите через ВКонтакте.', 'error');

            return Response::redirect($base . '/connect/vk');
        }
        try {
            $communities = $this->connections->communities($context, $credential);
        } catch (ChannelException $e) {
            $this->flash->session()->forget(self::PENDING_KEY);
            $this->flash->toast($e->getMessage(), 'error');

            return Response::redirect($base . '/connect/vk');
        }

        return $this->view->response('workspace/channels/choose_vk.twig', ['workspace' => $context, 'base' => $base, 'communities' => $communities]);
    }

    public function connect(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $this->requireEnabled();
        $base = '/w/' . $context->workspacePublicId . '/channels';
        $credential = $this->pending($context);
        if ($credential === null) {
            $this->flash->toast('Вход во ВКонтакте устарел. Начните заново.', 'error');

            return Response::redirect($base . '/connect/vk');
        }
        $ids = $request->input('communities');
        try {
            $channels = $this->connections->connect($context, $credential, is_array($ids) ? array_values(array_filter($ids, 'is_string')) : []);
        } catch (ChannelException $e) {
            $this->flash->toast($e->getMessage(), 'error');

            return Response::redirect($base . '/connect/vk/choose');
        }
        $this->flash->session()->forget(self::PENDING_KEY);
        $this->logger->info('vk.connected', ['workspace' => $context->workspacePublicId, 'channels' => count($channels)]);
        $this->flash->toast(count($channels) === 1 ? 'Сообщество «' . $channels[0]->displayName() . '» подключено. Теперь в него можно планировать посты.' : 'Подключили сообществ: ' . count($channels) . '. Теперь в них можно планировать посты.');

        return Response::redirect($base);
    }

    private function pending(WorkspaceContext $context): ?string
    {
        $pending = $this->flash->session()->get(self::PENDING_KEY);
        if (!is_array($pending) || !is_string($pending['credential'] ?? null) || ($pending['workspace'] ?? null) !== $context->workspacePublicId) {
            return null;
        }

        return $pending['credential'];
    }

    private function redirectUri(): string
    {
        return rtrim($this->config->string('app.url'), '/') . '/channels/connect/vk/callback';
    }

    private function requireEnabled(): void
    {
        if (!$this->registry->isEnabled(Platform::Vk)) {
            throw new HttpException(404, 'Not found');
        }
    }
}
