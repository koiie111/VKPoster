<?php

declare(strict_types=1);

namespace App\Domain\Channel;

use App\Domain\Workspace\Permissions;
use App\Domain\Workspace\WorkspaceContext;
use App\Domain\Workspace\WorkspaceRepository;
use App\Integrations\Social\Contracts\ChannelInfo;
use App\Integrations\Social\Contracts\Platform;
use App\Integrations\Social\Contracts\PlatformError;
use App\Integrations\Social\Telegram\TelegramClient;
use App\Integrations\Social\Telegram\TelegramClientFactory;
use App\Integrations\Social\Telegram\TelegramInspector;
use App\Kernel\Config;
use App\Kernel\Security\RateLimiter;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * What the shared bot does with an update from Telegram (a webhook call, or `telegram:poll` in development):
 *
 * - `/connect CODE` written in a channel or group connects it to the workspace that issued the code. The bot must be an
 *   administrator allowed to post; the person who wrote the message must be an administrator too (in a channel only
 *   administrators can write at all, in a group it is checked with `getChatMember`). The message with the code is deleted.
 * - the bot's membership changed (removed, rights taken away or returned): connected channels are re-checked at once.
 *
 * Never throws for bad input: whatever arrives is untrusted, and Telegram would only resend the update.
 */
final class TelegramUpdateHandler
{
    public const UPDATE_TYPES = ['message', 'channel_post', 'my_chat_member'];

    public function __construct(
        private readonly Config $config,
        private readonly TelegramClientFactory $clients,
        private readonly TelegramInspector $inspector,
        private readonly ConnectCodeRedeemer $codes,
        private readonly WorkspaceRepository $workspaces,
        private readonly Permissions $permissions,
        private readonly ChannelRepository $channels,
        private readonly ChannelService $service,
        private readonly ChannelSystem $system,
        private readonly ChannelHealthService $health,
        private readonly RateLimiter $limiter,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $update one `Update` object of the Bot API
     */
    public function handle(array $update): void
    {
        $token = $this->config->string('platforms.telegram.bot_token');
        if ($token === '') {
            return;
        }
        try {
            $client = $this->clients->make($token);
            if (is_array($update['my_chat_member'] ?? null)) {
                $this->membershipChanged($update['my_chat_member']);

                return;
            }
            $message = $update['channel_post'] ?? $update['message'] ?? null;
            if (is_array($message)) {
                $this->message($client, $message, is_array($update['channel_post'] ?? null), TelegramInspector::botIdFromToken($token));
            }
        } catch (PlatformError $e) {
            $this->logger->warning('Telegram update not handled', ['reason' => $e->getMessage()]);
        } catch (Throwable $e) {
            $this->logger->error('Telegram update failed', ['exception' => $e]);
        }
    }

    /**
     * @param array<string, mixed> $message
     */
    private function message(TelegramClient $client, array $message, bool $isChannelPost, ?int $botId): void
    {
        $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];
        $chatId = $chat['id'] ?? null;
        $type = is_string($chat['type'] ?? null) ? $chat['type'] : '';
        $text = is_string($message['text'] ?? null) ? trim($message['text']) : '';
        $messageId = is_int($message['message_id'] ?? null) ? $message['message_id'] : null;
        if (!is_int($chatId) || $text === '') {
            return;
        }
        if ($type === 'private') {
            if (str_starts_with($text, '/start') || str_starts_with($text, '/connect') || str_starts_with($text, '/help')) {
                $client->sendMessage($chatId, "Я публикую посты по расписанию. Чтобы подключить канал:\n1. Получите код на странице «Каналы» в сервисе.\n2. Добавьте меня администратором канала с правом «Публикация сообщений».\n3. Напишите в канале: /connect КОД");
            }

            return;
        }
        if (preg_match('~^/connect(?:@[A-Za-z0-9_]+)?\s+(\S.{0,30})$~u', $text, $m) !== 1 || !in_array($type, ['channel', 'supergroup', 'group'], true)) {
            return;
        }
        // Whatever the outcome, the code must not stay visible in the chat.
        $cleanup = static function () use ($client, $chatId, $messageId): void {
            if ($messageId !== null) {
                try {
                    $client->deleteMessage($chatId, $messageId);
                } catch (PlatformError) {
                    // The bot may lack the right to delete; the person can remove the message themselves.
                }
            }
        };

        // Per chat, and overall: guessing a code means writing /connect in some chat, so both bounds keep it hopeless.
        if (!$this->limiter->attempt('tg-connect:' . $chatId, 10, 600)->allowed || !$this->limiter->attempt('tg-connect:all', 600, 600)->allowed) {
            return;
        }
        $code = $this->codes->peek($m[1], Platform::Telegram);
        if ($code === null) {
            $this->logger->info('Telegram connect: unknown or expired code', ['chat' => $chatId]);
            $cleanup();

            return;
        }
        if (!$isChannelPost && !$this->senderIsAdmin($client, $chatId, $message)) {
            $this->logger->info('Telegram connect: sender is not an administrator', ['chat' => $chatId]);
            $cleanup();

            return;
        }

        try {
            $info = $this->inspector->inspect($client, $chatId, $botId);
        } catch (PlatformError $e) {
            $this->codes->fail($code['id'], $e->forUser());
            $cleanup();

            return;
        }
        $context = $this->contextFor($code['workspace_id'], $code['user_id']);
        if ($context === null || !$this->permissions->allows($context->role, 'channels.manage')) {
            $this->codes->fail($code['id'], 'У вас больше нет права подключать каналы в этом пространстве.');
            $cleanup();

            return;
        }
        try {
            $this->service->assertRoom($context);
        } catch (ChannelException $e) {
            $this->codes->fail($code['id'], $e->getMessage());
            $cleanup();

            return;
        }
        if (!$this->codes->consume($code['id'])) {
            $cleanup();

            return;
        }

        $result = $this->channels->connect($context, Platform::Telegram, $info->externalId, ChannelMode::SharedBot, $info->title, $info->username, $info->kind, null, ['rights' => $info->rights], $context->userId);
        $this->codes->attachChannel($code['id'], $result['channel']->id);
        $this->service->afterConnect($context, $result['channel'], $info, $client);
        $cleanup();
    }

    /**
     * @param array<string, mixed> $message
     */
    private function senderIsAdmin(TelegramClient $client, int $chatId, array $message): bool
    {
        $senderChat = is_array($message['sender_chat'] ?? null) ? $message['sender_chat'] : null;
        if ($senderChat !== null && ($senderChat['id'] ?? null) === $chatId) {
            return true; // an administrator writing anonymously, as the group itself
        }
        $from = is_array($message['from'] ?? null) ? $message['from'] : [];
        $userId = $from['id'] ?? null;
        if (!is_int($userId)) {
            return false;
        }
        try {
            $member = $client->getChatMember($chatId, $userId);
        } catch (PlatformError) {
            return false;
        }

        return in_array($member['status'] ?? null, ['creator', 'administrator'], true);
    }

    /**
     * @param array<string, mixed> $change
     */
    private function membershipChanged(array $change): void
    {
        $chat = is_array($change['chat'] ?? null) ? $change['chat'] : [];
        $chatId = $chat['id'] ?? null;
        if (!is_int($chatId)) {
            return;
        }
        // One check per channel, through the same path as the scheduled check: it reads the facts from Telegram instead of
        // trusting the contents of the update.
        foreach ($this->system->sharedBotChannels(Platform::Telegram, (string) $chatId) as $channel) {
            $this->health->check($channel);
        }
    }

    private function contextFor(int $workspaceId, int $userId): ?WorkspaceContext
    {
        $workspace = $this->workspaces->findById($workspaceId);
        $membership = $workspace === null ? null : $this->workspaces->membership($workspaceId, $userId);

        return $workspace !== null && $membership !== null ? WorkspaceContext::from($workspace, $membership) : null;
    }
}
