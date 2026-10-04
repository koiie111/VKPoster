<?php

declare(strict_types=1);

namespace App\Domain\Channel;

use App\Domain\Workspace\Permissions;
use App\Domain\Workspace\WorkspaceContext;
use App\Domain\Workspace\WorkspaceRepository;
use App\Integrations\Social\Contracts\Platform;
use App\Integrations\Social\Contracts\PlatformError;
use App\Integrations\Social\Max\MaxClient;
use App\Integrations\Social\Max\MaxClientFactory;
use App\Integrations\Social\Max\MaxInspector;
use App\Kernel\Config;
use App\Kernel\Security\RateLimiter;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * What the shared MAX bot does with an update (a webhook call, or `max:poll` in development):
 *
 * - `/connect CODE` written in a channel or group connects it to the workspace that issued the code. The bot must be allowed to post;
 *   in a group the person who wrote the message must be an administrator (in a channel only administrators can write at all).
 *   The message with the code is deleted afterwards.
 * - the bot was removed from a chat: connected channels are re-checked at once.
 * - a person writing to the bot in private gets a short instruction.
 *
 * Never throws for bad input: whatever arrives is untrusted, and MAX would only resend the update.
 */
final class MaxUpdateHandler
{
    public const UPDATE_TYPES = ['message_created', 'bot_removed'];

    public function __construct(
        private readonly Config $config,
        private readonly MaxClientFactory $clients,
        private readonly MaxInspector $inspector,
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
     * @param array<string, mixed> $update one update object of the bot API
     */
    public function handle(array $update): void
    {
        $token = $this->config->string('platforms.max.bot_token');
        if ($token === '') {
            return;
        }
        try {
            $type = $update['update_type'] ?? null;
            if ($type === 'bot_removed') {
                $this->botRemoved($update);
            } elseif ($type === 'message_created' && is_array($update['message'] ?? null)) {
                $this->message($this->clients->make($token), $update['message']);
            }
        } catch (PlatformError $e) {
            $this->logger->warning('MAX update not handled', ['reason' => $e->getMessage()]);
        } catch (Throwable $e) {
            $this->logger->error('MAX update failed', ['exception' => $e]);
        }
    }

    /**
     * @param array<string, mixed> $message
     */
    private function message(MaxClient $client, array $message): void
    {
        $recipient = is_array($message['recipient'] ?? null) ? $message['recipient'] : [];
        $body = is_array($message['body'] ?? null) ? $message['body'] : [];
        $sender = is_array($message['sender'] ?? null) ? $message['sender'] : null;
        $chatType = is_string($recipient['chat_type'] ?? null) ? $recipient['chat_type'] : '';
        $chatId = $recipient['chat_id'] ?? null;
        $text = is_string($body['text'] ?? null) ? trim($body['text']) : '';
        $messageId = is_string($body['mid'] ?? null) && $body['mid'] !== '' ? $body['mid'] : null;
        if ($text === '' || ($sender !== null && ($sender['is_bot'] ?? false) === true)) {
            return;
        }
        if ($chatType === 'dialog') {
            $userId = $sender['user_id'] ?? null;
            if (is_int($userId) && (str_starts_with($text, '/start') || str_starts_with($text, '/connect') || str_starts_with($text, '/help'))) {
                $client->sendDirect($userId, "Я публикую посты по расписанию. Чтобы подключить канал:\n1. Получите код на странице «Каналы» в сервисе.\n2. Добавьте меня администратором канала с правом публикации сообщений.\n3. Напишите в канале: /connect КОД");
            }

            return;
        }
        if (!is_int($chatId) || !in_array($chatType, ['channel', 'chat'], true)
            || preg_match('~^/connect(?:@[A-Za-z0-9_.\-]+)?\s+(\S.{0,30})$~u', $text, $m) !== 1) {
            return;
        }
        // Whatever the outcome, the code must not stay visible in the chat.
        $cleanup = static function () use ($client, $messageId): void {
            if ($messageId !== null) {
                try {
                    $client->deleteMessage($messageId);
                } catch (PlatformError) {
                    // The bot may lack the right to delete; the person can remove the message themselves.
                }
            }
        };

        // Per chat, and overall: guessing a code means writing /connect in some chat, so both bounds keep it hopeless.
        if (!$this->limiter->attempt('max-connect:' . $chatId, 10, 600)->allowed || !$this->limiter->attempt('max-connect:all', 600, 600)->allowed) {
            return;
        }
        $code = $this->codes->peek($m[1], Platform::Max);
        if ($code === null) {
            $this->logger->info('MAX connect: unknown or expired code', ['chat' => $chatId]);
            $cleanup();

            return;
        }
        // A channel post has no sender: only administrators can write there, so who wrote it is settled.
        if ($chatType === 'chat' && !$this->senderIsAdmin($client, $chatId, $sender)) {
            $this->logger->info('MAX connect: sender is not an administrator', ['chat' => $chatId]);
            $cleanup();

            return;
        }

        try {
            $info = $this->inspector->inspect($client, $chatId);
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

        $result = $this->channels->connect($context, Platform::Max, $info->externalId, ChannelMode::SharedBot, $info->title, $info->username, $info->kind, null, ['rights' => $info->rights], $context->userId);
        $this->codes->attachChannel($code['id'], $result['channel']->id);
        $this->service->recordConnected($context, $result['channel']);
        $cleanup();
    }

    /**
     * @param array<string, mixed>|null $sender
     */
    private function senderIsAdmin(MaxClient $client, int $chatId, ?array $sender): bool
    {
        $userId = $sender['user_id'] ?? null;
        if (!is_int($userId)) {
            return false;
        }
        try {
            $members = $client->getMembers($chatId, [$userId]);
        } catch (PlatformError) {
            return false;
        }
        foreach ($members as $member) {
            if (($member['user_id'] ?? null) === $userId) {
                return ($member['is_admin'] ?? false) === true || ($member['is_owner'] ?? false) === true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $update
     */
    private function botRemoved(array $update): void
    {
        $chatId = $update['chat_id'] ?? null;
        if (!is_int($chatId)) {
            return;
        }
        // One check per channel, through the same path as the scheduled check: it reads the facts from MAX instead of trusting the update.
        foreach ($this->system->sharedBotChannels(Platform::Max, (string) $chatId) as $channel) {
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
