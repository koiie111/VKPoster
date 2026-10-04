<?php

declare(strict_types=1);

namespace App\Domain\Channel;

use App\Domain\Audit\AuditLog;
use App\Domain\Workspace\WorkspaceContext;
use App\Integrations\Social\Contracts\ChannelInfo;
use App\Integrations\Social\Contracts\Credential;
use App\Integrations\Social\Contracts\Platform;
use App\Integrations\Social\Contracts\PlatformError;
use App\Integrations\Social\PlatformRegistry;
use App\Integrations\Social\Telegram\TelegramClientFactory;
use App\Kernel\Config;
use App\Kernel\Database\Connection;
use SensitiveParameter;

/**
 * What a member can do with channels of a workspace: connect with an own bot or the test network, pause, resume, rename,
 * check, disconnect. Permission checks (`channels.manage`) happen in the routes; limits, audit and the rules about secrets live here.
 * Connecting through the shared bot is `TelegramUpdateHandler`, because it starts in the bot, not on a page.
 */
final class ChannelService
{
    private const TOKEN_PATTERN = '/^\d{5,15}:[A-Za-z0-9_-]{30,60}$/';

    public function __construct(
        private readonly Connection $db,
        private readonly ChannelRepository $channels,
        private readonly CredentialVault $vault,
        private readonly PlatformRegistry $registry,
        private readonly ChannelHealthService $health,
        private readonly ChannelAvatars $avatars,
        private readonly TelegramClientFactory $telegram,
        private readonly AuditLog $audit,
        private readonly Config $config,
    ) {
    }

    /**
     * Connect a channel with the customer's own Telegram bot.
     *
     * @throws ChannelException with a message for the person
     */
    public function connectOwnTelegramBot(WorkspaceContext $context, #[SensitiveParameter] string $token, string $reference): Channel
    {
        $connector = $this->registry->connector(Platform::Telegram) ?? throw new ChannelException('Подключение Telegram сейчас выключено.');
        $token = trim($token);
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            throw new ChannelException('Токен выглядит неправильно. Он состоит из цифр, двоеточия и длинного набора символов, например 123456:ABC-DEF…');
        }
        $this->assertRoom($context);
        try {
            $info = $connector->connect(new Credential(Platform::Telegram, $token), $reference);
        } catch (PlatformError $e) {
            throw new ChannelException($e->forUser());
        }

        // The same chat connected again (after the token was replaced, say) keeps its row, and the old credential is replaced in place.
        $existing = $this->channels->findByExternal($context, Platform::Telegram, $info->externalId);
        $credentialId = $this->db->transaction(function () use ($context, $token, $existing): int {
            if ($existing !== null && $existing->credentialId !== null && $existing->mode === ChannelMode::OwnBot) {
                $this->vault->replace($context, $existing->credentialId, $token);

                return $existing->credentialId;
            }

            return $this->vault->store($context, Platform::Telegram, 'bot_token', $token);
        });
        $result = $this->channels->connect($context, Platform::Telegram, $info->externalId, ChannelMode::OwnBot, $info->title, $info->username, $info->kind, $credentialId, ['rights' => $info->rights], $context->userId);
        if ($existing !== null && $existing->credentialId !== null && $existing->credentialId !== $credentialId && $existing->mode === ChannelMode::OwnBot) {
            $this->vault->deleteIfUnused($context, $existing->credentialId);
        }
        $this->afterConnect($context, $result['channel'], $info, $this->telegram->make($token));

        return $result['channel'];
    }

    /**
     * Connect a channel of the test network (dev and tests only; the registry hides it in production).
     *
     * @throws ChannelException
     */
    public function connectFake(WorkspaceContext $context, string $name): Channel
    {
        if (!$this->registry->isEnabled(Platform::Fake)) {
            throw new ChannelException('Тестовая сеть выключена.');
        }
        $this->assertRoom($context);
        $name = trim($name) !== '' ? mb_substr(trim($name), 0, 100) : 'Тестовый канал';
        $result = $this->channels->connect($context, Platform::Fake, 'fake-' . bin2hex(random_bytes(4)), ChannelMode::SharedBot, $name, null, 'channel', null, ['rights' => ['post' => true, 'edit' => true, 'delete' => true, 'pin' => true]], $context->userId);
        $this->recordConnected($context, $result['channel']);

        return $result['channel'];
    }

    /**
     * Called by `TelegramUpdateHandler` and the own-bot flow once a channel row exists.
     */
    public function afterConnect(WorkspaceContext $context, Channel $channel, ChannelInfo $info, \App\Integrations\Social\Telegram\TelegramClient $client): void
    {
        $this->recordConnected($context, $channel);
        $this->avatars->refresh($channel, $client, $info->avatarFileId);
    }

    public function recordConnected(WorkspaceContext $context, Channel $channel): void
    {
        $this->audit->record('channel.connected', $context->userId, 'channel', $channel->publicId, ['platform' => $channel->platform->value, 'name' => $channel->displayName()], $context->workspaceId);
    }

    /**
     * @throws ChannelException when the workspace has reached its channel limit
     */
    public function assertRoom(WorkspaceContext $context): void
    {
        $max = $this->config->int('platforms.channels.max_per_workspace', 100);
        if ($this->channels->count($context) >= $max) {
            throw new ChannelException(sprintf('Достигнут лимит каналов в пространстве: %d. Отключите ненужный канал, чтобы подключить новый.', $max));
        }
    }

    public function pause(WorkspaceContext $context, Channel $channel): void
    {
        $this->channels->setStatus($context, $channel, ChannelStatus::Paused, $channel->lastError);
        $this->audit->record('channel.paused', $context->userId, 'channel', $channel->publicId, ['name' => $channel->displayName()], $context->workspaceId);
    }

    /**
     * Resume publishing; the channel is checked first, so a channel that broke while paused does not silently come back as "active".
     */
    public function resume(WorkspaceContext $context, Channel $channel): Channel
    {
        $this->channels->setStatus($context, $channel, ChannelStatus::Active);
        $this->audit->record('channel.resumed', $context->userId, 'channel', $channel->publicId, ['name' => $channel->displayName()], $context->workspaceId);

        return $this->checkNow($context, $this->channels->find($context, $channel->publicId) ?? $channel);
    }

    public function checkNow(WorkspaceContext $context, Channel $channel): Channel
    {
        return $this->health->check($channel);
    }

    public function rename(WorkspaceContext $context, Channel $channel, string $alias): void
    {
        $this->channels->rename($context, $channel, $alias);
        $this->audit->record('channel.renamed', $context->userId, 'channel', $channel->publicId, ['name' => $alias === '' ? $channel->title : $alias], $context->workspaceId);
    }

    /**
     * Disconnect. The channel itself is untouched; our row, its access entries and (for an own bot) the stored token go.
     */
    public function remove(WorkspaceContext $context, Channel $channel): void
    {
        $this->db->transaction(function () use ($context, $channel): void {
            $this->channels->delete($context, $channel);
            if ($channel->credentialId !== null) {
                $this->vault->deleteIfUnused($context, $channel->credentialId);
            }
        });
        $this->avatars->delete($channel);
        $this->audit->record('channel.disconnected', $context->userId, 'channel', $channel->publicId, ['platform' => $channel->platform->value, 'name' => $channel->displayName()], $context->workspaceId);
    }
}
