<?php

declare(strict_types=1);

namespace App\Kernel\Console\Command;

use App\Domain\Channel\TelegramUpdateHandler;
use App\Integrations\Social\Contracts\ErrorKind;
use App\Integrations\Social\Contracts\PlatformError;
use App\Integrations\Social\Telegram\TelegramClientFactory;
use App\Kernel\Config;
use App\Kernel\Console\Command;
use App\Kernel\Console\Output;

/**
 * `telegram:poll [--once]`: long polling (`getUpdates`) for local development, where Telegram cannot reach a webhook. Runs the
 * same handler as the webhook. Removes a set webhook first (Telegram allows only one of the two). Stops on SIGTERM/SIGINT.
 */
final class TelegramPollCommand implements Command
{
    private bool $stop = false;

    public function __construct(
        private readonly Config $config,
        private readonly TelegramClientFactory $clients,
        private readonly TelegramUpdateHandler $handler,
    ) {
    }

    public function name(): string
    {
        return 'telegram:poll';
    }

    public function description(): string
    {
        return 'Receive bot updates by long polling (development, no public HTTPS needed)';
    }

    public function run(array $args, Output $out): int
    {
        $token = $this->config->string('platforms.telegram.bot_token');
        if ($token === '') {
            $out->error('TELEGRAM_BOT_TOKEN is not set.');

            return 1;
        }
        if ($this->config->isProduction()) {
            $out->error('Polling is a development tool; production uses the webhook.');

            return 1;
        }
        $once = in_array('--once', $args, true);
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, function (): void {
                $this->stop = true;
            });
            pcntl_signal(SIGINT, function (): void {
                $this->stop = true;
            });
        }
        $client = $this->clients->make($token);
        try {
            $client->deleteWebhook();
            $me = $client->getMe();
        } catch (PlatformError $e) {
            $out->error($e->getMessage());

            return 1;
        }
        $out->line('Polling as @' . (is_string($me['username'] ?? null) ? $me['username'] : '?') . '. Press Ctrl+C to stop.');

        $offset = 0;
        while (!$this->stop) {
            try {
                $updates = $client->getUpdates($offset, $once ? 0 : 25, TelegramUpdateHandler::UPDATE_TYPES);
            } catch (PlatformError $e) {
                $out->error($e->getMessage());
                if ($once || $e->kind === ErrorKind::Auth) {
                    return 1;
                }
                sleep($e->retryAfter ?? 3);
                continue;
            }
            foreach ($updates as $update) {
                $offset = max($offset, (is_int($update['update_id'] ?? null) ? $update['update_id'] : 0) + 1);
                $this->handler->handle($update);
            }
            if ($updates !== []) {
                $out->line(sprintf('Handled %d update(s).', count($updates)));
            }
            if ($once) {
                break;
            }
        }

        return 0;
    }
}
