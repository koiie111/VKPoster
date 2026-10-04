<?php

declare(strict_types=1);

namespace App\Domain\Notification;

use App\Integrations\Social\Telegram\TelegramClientFactory;
use App\Integrations\Social\Contracts\ErrorKind;
use App\Integrations\Social\Contracts\PlatformError;
use App\Kernel\Config;
use App\Kernel\Queue\AbstractJob;
use Psr\Log\LoggerInterface;

/**
 * Sends one notification to a person's private chat through the shared bot. A passing failure is retried by the queue; a chat that
 * no longer accepts the bot (the person blocked it) is dropped quietly, since retrying cannot help.
 */
final class SendTelegramNotificationJob extends AbstractJob
{
    public function __construct(public readonly int $chatId, public readonly string $text)
    {
    }

    public static function fromPayload(array $payload): static
    {
        return new static(is_int($payload['chat_id'] ?? null) ? $payload['chat_id'] : 0, is_string($payload['text'] ?? null) ? $payload['text'] : '');
    }

    public function toPayload(): array
    {
        return ['chat_id' => $this->chatId, 'text' => $this->text];
    }

    public function handle(Config $config, TelegramClientFactory $clients, LoggerInterface $logger): void
    {
        $token = $config->string('platforms.telegram.bot_token');
        if ($token === '' || $this->chatId === 0) {
            return;
        }
        try {
            $clients->make($token)->sendMessage($this->chatId, $this->text, ['link_preview_options' => ['is_disabled' => true]]);
        } catch (PlatformError $e) {
            if ($e->kind === ErrorKind::Temporary || $e->kind === ErrorKind::RateLimited || $e->kind === ErrorKind::UnknownOutcome) {
                throw $e;
            }
            $logger->info('Telegram notification not delivered', ['reason' => $e->getMessage()]);
        }
    }
}
