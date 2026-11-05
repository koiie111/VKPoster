<?php

declare(strict_types=1);

namespace App\Domain\Channel;

use App\Domain\Workspace\WorkspaceContext;
use App\Integrations\OAuth\Pkce;
use App\Integrations\Social\Contracts\Platform;
use App\Integrations\Social\Contracts\PlatformError;
use App\Integrations\Social\YouTube\YouTubeApi;
use App\Integrations\Social\YouTube\YouTubeOAuth;
use App\Support\Clock;
use Psr\Log\LoggerInterface;

/** Connect the single YouTube channel bound to a Google OAuth grant, separate from Google sign-in. */
final class YouTubeConnections
{
    public function __construct(
        private readonly YouTubeOAuth $oauth,
        private readonly YouTubeApi $api,
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

    /** @return array{url: string, state: string, verifier: string} */
    public function begin(string $redirectUri): array
    {
        $state = Pkce::base64Url(random_bytes(32));
        $pkce = Pkce::generate();
        return ['url' => $this->oauth->authorizationUrl($redirectUri, $state, $pkce->challenge), 'state' => $state, 'verifier' => $pkce->verifier];
    }

    public function complete(WorkspaceContext $context, string $code, string $verifier, string $redirectUri): Channel
    {
        try {
            $tokens = $this->oauth->exchange($code, $redirectUri, $verifier);
            $response = $this->api->channels($tokens->accessToken);
        } catch (PlatformError $error) {
            $this->logger->info('youtube.connect.failed', ['reason' => $error->getMessage()]);
            throw new ChannelException($error->forUser());
        }
        $items = is_array($response['items'] ?? null) ? array_values(array_filter($response['items'], 'is_array')) : [];
        if (count($items) !== 1 || !is_string($items[0]['id'] ?? null) || $items[0]['id'] === '') {
            throw new ChannelException('Google не выбрал один канал YouTube. Выберите нужный канал при входе и попробуйте снова.');
        }
        $id = $items[0]['id'];
        $snippet = is_array($items[0]['snippet'] ?? null) ? $items[0]['snippet'] : [];
        $name = is_string($snippet['title'] ?? null) && $snippet['title'] !== '' ? $snippet['title'] : 'YouTube';
        $existing = $this->channels->findByExternal($context, Platform::YouTube, $id);
        if ($existing === null) {
            $this->service->assertRoom($context);
        }
        $this->vault->pruneAbandoned($context, Platform::YouTube);
        $credentialId = $this->vault->storeOAuth(
            $context,
            Platform::YouTube,
            $tokens->accessToken,
            $tokens->refreshToken,
            $this->clock->now()->modify(sprintf('+%d seconds', $tokens->expiresIn)),
            $id,
            $id,
            $tokens->scope
        );
        $old = $existing?->credentialId;
        try {
            $connected = $this->channels->connect(
                $context,
                Platform::YouTube,
                $id,
                ChannelMode::Account,
                $name,
                null,
                'channel',
                $credentialId,
                ['rights' => ['post' => true, 'edit' => true, 'delete' => true, 'pin' => false]],
                $context->userId
            );
        } catch (\Throwable $error) {
            $this->vault->deleteIfUnused($context, $credentialId);
            throw $error;
        }
        if ($old !== null && $old !== $credentialId) {
            $this->vault->deleteIfUnused($context, $old);
        }
        $this->service->recordConnected($context, $connected['channel']);
        return $connected['channel'];
    }
}
