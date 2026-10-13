<?php

declare(strict_types=1);

namespace App\Domain\Channel;

use App\Domain\Workspace\WorkspaceContext;
use App\Integrations\OAuth\Pkce;
use App\Integrations\Social\Contracts\Platform;
use App\Integrations\Social\Contracts\PlatformError;
use App\Integrations\Social\Instagram\InstagramAccounts;
use App\Integrations\Social\Instagram\InstagramOAuth;
use App\Support\Clock;
use Psr\Log\LoggerInterface;

/**
 * Connecting an Instagram account: the person signs in at Instagram and allows publishing, we keep the long-lived token (encrypted, one
 * credential row) and turn the professional account behind it into one channel. Unlike VK there is nothing to choose: a sign-in is exactly
 * one account, and personal accounts are refused because the API publishes only for professional ones.
 */
final class InstagramConnections
{
    public function __construct(
        private readonly InstagramOAuth $oauth,
        private readonly InstagramAccounts $accounts,
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
     * @return array{url: string, state: string}
     */
    public function begin(string $redirectUri): array
    {
        $state = Pkce::base64Url(random_bytes(32));

        return ['url' => $this->oauth->authorizationUrl($redirectUri, $state), 'state' => $state];
    }

    /**
     * Exchange the code from the callback, check the account and connect it as a channel (an account that is already connected is refreshed
     * in place, keeping its scheduled posts).
     *
     * @throws ChannelException with a message for the person
     */
    public function complete(WorkspaceContext $context, string $code, string $redirectUri): Channel
    {
        try {
            $tokens = $this->oauth->exchangeCode($code, $redirectUri);
            $profile = $this->accounts->profile($tokens->accessToken);
        } catch (PlatformError $e) {
            $this->logger->info('instagram.connect.failed', ['reason' => $e->getMessage()]);
            throw new ChannelException($e->kind->value === 'auth' ? 'Instagram не подтвердил вход. Попробуйте ещё раз: нажмите «Войти через Instagram» и разрешите все права.' : $e->forUser());
        }
        if (!$profile['professional']) {
            throw new ChannelException('Это личный аккаунт Instagram. Публиковать можно только в профессиональный аккаунт (бизнес или автор): переключите его в настройках Instagram и подключите снова.');
        }
        $existing = $this->channels->findByExternal($context, Platform::Instagram, $profile['id']);
        if ($existing === null) {
            $this->service->assertRoom($context);
        }
        $this->vault->pruneAbandoned($context, Platform::Instagram);
        $credentialId = $this->vault->storeOAuth($context, Platform::Instagram, $tokens->accessToken, '', $this->clock->now()->modify(sprintf('+%d seconds', $tokens->expiresIn)), '', $profile['id'], $tokens->scope);
        $old = $existing?->credentialId;
        try {
            $result = $this->channels->connect($context, Platform::Instagram, $profile['id'], ChannelMode::Account, $profile['name'], $profile['username'], 'channel', $credentialId, ['rights' => ['post' => true, 'edit' => false, 'delete' => false, 'pin' => false]], $context->userId);
        } catch (\Throwable $e) {
            $this->vault->deleteIfUnused($context, $credentialId);
            throw $e;
        }
        if ($old !== null && $old !== $credentialId) {
            $this->vault->deleteIfUnused($context, $old);
        }
        $this->service->recordConnected($context, $result['channel']);

        return $result['channel'];
    }
}
