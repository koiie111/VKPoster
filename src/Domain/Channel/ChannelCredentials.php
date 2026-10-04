<?php

declare(strict_types=1);

namespace App\Domain\Channel;

use App\Integrations\Social\Contracts\Credential;
use App\Integrations\Social\Contracts\ErrorKind;
use App\Integrations\Social\Contracts\Platform;
use App\Integrations\Social\Contracts\PlatformError;
use App\Kernel\Config;
use App\Kernel\Database\Connection;
use App\Kernel\Security\Crypto;

/**
 * Decrypts the secret a channel publishes with, right before a call (publishing, health check). The secret is looked up
 * by the channel's own workspace id, so a channel can never be paired with a credential of another workspace.
 */
final class ChannelCredentials
{
    public function __construct(
        private readonly Connection $db,
        private readonly Crypto $crypto,
        private readonly Config $config,
    ) {
    }

    /**
     * @throws PlatformError (auth) when the channel has no usable secret: no shared bot configured, or the stored token is gone
     */
    public function forChannel(Channel $channel): Credential
    {
        if ($channel->platform === Platform::Fake) {
            return new Credential(Platform::Fake, 'fake');
        }
        if ($channel->mode === ChannelMode::SharedBot) {
            $token = $this->config->string('platforms.' . $channel->platform->value . '.bot_token');
            if ($token === '') {
                throw new PlatformError(ErrorKind::Auth, 'The shared bot token is not configured.', 'Бот сервиса сейчас не настроен. Мы уже знаем о проблеме.');
            }

            return new Credential($channel->platform, $token);
        }
        $row = $channel->credentialId === null ? null : $this->db->table('platform_credentials')
            ->where('id', '=', $channel->credentialId)->where('workspace_id', '=', $channel->workspaceId)->first();
        if ($row === null) {
            throw new PlatformError(ErrorKind::Auth, 'The channel has no stored credential.', 'Токен бота не найден. Подключите канал заново.');
        }

        return new Credential($channel->platform, $this->crypto->decrypt((string) $row['secret_enc']));
    }
}
