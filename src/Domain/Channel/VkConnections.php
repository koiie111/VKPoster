<?php

declare(strict_types=1);

namespace App\Domain\Channel;

use App\Domain\Workspace\WorkspaceContext;
use App\Integrations\OAuth\Pkce;
use App\Integrations\Social\Contracts\Platform;
use App\Integrations\Social\Contracts\PlatformError;
use App\Integrations\Social\Vk\VkCommunities;
use App\Integrations\Social\Vk\VkOAuth;
use App\Support\Clock;
use Psr\Log\LoggerInterface;

/**
 * Connecting VK communities: the person signs in at VK ID with the rights to post on walls, we keep the token pair (encrypted, one credential row),
 * list the communities they administer or edit, and turn the chosen ones into channels that all share that credential. Which communities may be
 * connected is decided by VK's answer at the moment of choosing, never by what the form says.
 */
final class VkConnections
{
    public function __construct(
        private readonly VkOAuth $oauth,
        private readonly VkCommunities $communities,
        private readonly CredentialVault $vault,
        private readonly ChannelRepository $channels,
        private readonly ChannelService $service,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function available(): bool
    {
        return $this->oauth->configured();
    }

    /**
     * @return array{url: string, state: string, verifier: string}
     */
    public function begin(string $redirectUri): array
    {
        $state = Pkce::base64Url(random_bytes(32));
        $pkce = Pkce::generate();

        return ['url' => $this->oauth->authorizationUrl($redirectUri, $state, $pkce), 'state' => $state, 'verifier' => $pkce->verifier];
    }

    /**
     * Exchange the code from the callback and keep the tokens. Returns the public id of the credential for the choosing step.
     *
     * @throws ChannelException with a message for the person
     */
    public function complete(WorkspaceContext $context, string $code, string $verifier, string $redirectUri, string $deviceId, string $state): string
    {
        try {
            ['tokens' => $tokens] = $this->oauth->exchangeCode($code, $verifier, $redirectUri, $deviceId, $state);
        } catch (PlatformError $e) {
            $this->logger->info('vk.connect.exchange_failed', ['reason' => $e->getMessage()]);
            throw new ChannelException('ВКонтакте не подтвердил вход. Попробуйте ещё раз: нажмите «Войти через ВКонтакте» и разрешите доступ.');
        }
        $this->vault->pruneAbandoned($context, Platform::Vk);
        $id = $this->vault->storeOAuth($context, Platform::Vk, $tokens->accessToken, $tokens->refreshToken, $this->clock->now()->modify(sprintf('+%d seconds', $tokens->expiresIn)), $deviceId, $tokens->userId, $tokens->scope);
        $publicId = $this->publicIdOf($context, $id);
        try {
            $this->communities($context, $publicId);
        } catch (ChannelException $e) {
            // The token is of no use (rights not granted, VK down): do not keep it.
            $this->vault->deleteIfUnused($context, $id);
            throw $e;
        }

        return $publicId;
    }

    /**
     * The communities the account manages, each marked when already connected in this workspace.
     *
     * @return list<array{id: string, name: string, handle: string, connected: bool}>
     * @throws ChannelException
     */
    public function communities(WorkspaceContext $context, string $credentialPublicId): array
    {
        $pending = $this->vault->pendingOAuth($context, Platform::Vk, $credentialPublicId) ?? throw new ChannelException('Вход во ВКонтакте устарел. Начните подключение заново.');
        try {
            $groups = $this->communities->managedBy($pending['token']);
        } catch (PlatformError $e) {
            throw new ChannelException($e->kind->value === 'auth' ? 'ВКонтакте не дал доступ к списку сообществ. Разрешите все запрошенные права и попробуйте снова.' : $e->forUser());
        }
        $list = [];
        foreach ($groups as $group) {
            if ($group['closed']) {
                continue;
            }
            $list[] = [
                'id' => $group['id'],
                'name' => $group['name'],
                'handle' => $group['screen_name'] !== null ? 'vk.com/' . $group['screen_name'] : 'vk.com/club' . $group['id'],
                'connected' => $this->channels->findByExternal($context, Platform::Vk, $group['id']) !== null,
            ];
        }

        return $list;
    }

    /**
     * @param list<string> $groupIds ids chosen on the page
     * @return list<Channel> the channels now connected
     * @throws ChannelException
     */
    public function connect(WorkspaceContext $context, string $credentialPublicId, array $groupIds): array
    {
        $pending = $this->vault->pendingOAuth($context, Platform::Vk, $credentialPublicId) ?? throw new ChannelException('Вход во ВКонтакте устарел. Начните подключение заново.');
        $chosen = array_values(array_unique(array_filter($groupIds, static fn (string $id): bool => preg_match('/^\d{1,12}$/', $id) === 1)));
        if ($chosen === []) {
            throw new ChannelException('Выберите хотя бы одно сообщество.');
        }
        try {
            $managed = $this->communities->managedBy($pending['token']);
        } catch (PlatformError $e) {
            throw new ChannelException($e->forUser());
        }
        $byId = [];
        foreach ($managed as $group) {
            $byId[$group['id']] = $group;
        }

        $connected = [];
        foreach ($chosen as $id) {
            $group = $byId[$id] ?? null;
            if ($group === null || $group['closed']) {
                continue; // not managed by this account (a forged id, or rights were lost since the list was shown)
            }
            $existing = $this->channels->findByExternal($context, Platform::Vk, $id);
            if ($existing === null) {
                $this->service->assertRoom($context);
            }
            $old = $existing?->credentialId;
            $result = $this->channels->connect($context, Platform::Vk, $id, ChannelMode::Account, $group['name'], $group['screen_name'], 'group', $pending['id'], ['rights' => ['post' => true, 'edit' => true, 'delete' => true, 'pin' => true]], $context->userId);
            if ($old !== null && $old !== $pending['id']) {
                $this->vault->deleteIfUnused($context, $old);
            }
            $this->service->recordConnected($context, $result['channel']);
            $connected[] = $result['channel'];
        }
        if ($connected === []) {
            $this->vault->deleteIfUnused($context, $pending['id']);
            throw new ChannelException('Эти сообщества не подошли: подключить можно только те, где вы администратор или редактор.');
        }

        return $connected;
    }

    private function publicIdOf(WorkspaceContext $context, int $credentialId): string
    {
        return $this->vault->publicIdOf($context, $credentialId) ?? throw new ChannelException('Не удалось сохранить доступ. Попробуйте ещё раз.');
    }
}
