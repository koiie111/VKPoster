<?php

declare(strict_types=1);

namespace App\Domain\Channel;

use App\Domain\Workspace\WorkspaceContext;
use App\Integrations\OAuth\Pkce;
use App\Integrations\Social\Contracts\Platform;
use App\Integrations\Social\Contracts\PlatformError;
use App\Integrations\Social\TikTok\TikTokApi;
use App\Integrations\Social\TikTok\TikTokOAuth;
use App\Support\Clock;
use Psr\Log\LoggerInterface;

/** Bind a TikTok Login Kit identity to a workspace channel with encrypted rotating tokens. */
final class TikTokConnections
{
    public function __construct(
        private readonly TikTokOAuth $oauth,
        private readonly TikTokApi $api,
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

    /** @return array{url: string, state: string} */
    public function begin(string $redirectUri): array
    {
        $state = Pkce::base64Url(random_bytes(32));
        return ['url' => $this->oauth->authorizationUrl($redirectUri, $state), 'state' => $state];
    }

    public function complete(WorkspaceContext $context, string $code, string $redirectUri): Channel
    {
        try {
            $tokens = $this->oauth->exchange($code, $redirectUri);
            $creator = $this->api->creator($tokens->accessToken);
        } catch (PlatformError $error) {
            $this->logger->info('tiktok.connect.failed', ['reason' => $error->getMessage()]);
            throw new ChannelException($error->forUser());
        }
        $existing = $this->channels->findByExternal($context, Platform::TikTok, $tokens->openId);
        if ($existing === null) {
            $this->service->assertRoom($context);
        }
        $this->vault->pruneAbandoned($context, Platform::TikTok);
        $credentialId = $this->vault->storeOAuth(
            $context,
            Platform::TikTok,
            $tokens->accessToken,
            $tokens->refreshToken,
            $this->clock->now()->modify(sprintf('+%d seconds', $tokens->expiresIn)),
            $tokens->openId,
            $tokens->openId,
            $tokens->scope
        );
        $name = is_string($creator['creator_nickname'] ?? null) && $creator['creator_nickname'] !== '' ? $creator['creator_nickname'] : 'TikTok';
        $username = is_string($creator['creator_username'] ?? null) && $creator['creator_username'] !== '' ? $creator['creator_username'] : null;
        $old = $existing?->credentialId;
        try {
            $connected = $this->channels->connect(
                $context,
                Platform::TikTok,
                $tokens->openId,
                ChannelMode::Account,
                $name,
                $username,
                'channel',
                $credentialId,
                ['rights' => ['post' => true, 'edit' => false, 'delete' => false, 'pin' => false]],
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
