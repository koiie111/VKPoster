<?php

declare(strict_types=1);

namespace App\Kernel\Console\Command;

use App\Integrations\Social\Contracts\PlatformError;
use App\Integrations\Social\Telegram\TelegramClientFactory;
use App\Integrations\Social\Telegram\TelegramWebhook;
use App\Kernel\Config;
use App\Kernel\Console\Command;
use App\Kernel\Console\Output;
use App\Domain\Channel\TelegramUpdateHandler;

/**
 * `telegram:webhook set [URL] | delete | info`: point the shared bot at this server. `set` uses `APP_URL` unless given a public
 * base URL (a tunnel). Needs TELEGRAM_BOT_TOKEN and TELEGRAM_WEBHOOK_SECRET; the secret never gets printed.
 */
final class TelegramWebhookCommand implements Command
{
    public function __construct(private readonly Config $config, private readonly TelegramClientFactory $clients)
    {
    }

    public function name(): string
    {
        return 'telegram:webhook';
    }

    public function description(): string
    {
        return 'Manage the shared bot webhook: set [PUBLIC_URL] | delete | info';
    }

    public function run(array $args, Output $out): int
    {
        $token = $this->config->string('platforms.telegram.bot_token');
        if ($token === '') {
            $out->error('TELEGRAM_BOT_TOKEN is not set.');

            return 1;
        }
        $client = $this->clients->make($token);
        $action = $args[0] ?? 'info';
        try {
            if ($action === 'set') {
                $secret = $this->config->string('platforms.telegram.webhook_secret');
                if (preg_match('/^[A-Za-z0-9_-]{16,128}$/', $secret) !== 1) {
                    $out->error('Set TELEGRAM_WEBHOOK_SECRET to 16-128 characters of A-Z a-z 0-9 _ - first, for example: ' . bin2hex(random_bytes(16)));

                    return 1;
                }
                $base = $args[1] ?? $this->config->string('app.url');
                if (!str_starts_with($base, 'https://')) {
                    $out->error('Telegram needs a public https:// address (use the tunnel profile, or `telegram:poll` for local development).');

                    return 1;
                }
                $client->setWebhook(TelegramWebhook::url($base, $secret), TelegramWebhook::headerToken($secret), TelegramUpdateHandler::UPDATE_TYPES);
                $out->line('Webhook set to ' . rtrim($base, '/') . '/webhooks/telegram/***');
            } elseif ($action === 'delete') {
                $client->deleteWebhook();
                $out->line('Webhook removed.');
            } else {
                $info = $client->getWebhookInfo();
                $url = is_string($info['url'] ?? null) && $info['url'] !== '' ? preg_replace('~/webhooks/telegram/.*$~', '/webhooks/telegram/***', $info['url']) : '(none)';
                $out->line('url: ' . $url);
                $out->line('pending updates: ' . (is_int($info['pending_update_count'] ?? null) ? $info['pending_update_count'] : 0));
                if (is_string($info['last_error_message'] ?? null)) {
                    $out->line('last error: ' . $info['last_error_message']);
                }
            }
        } catch (PlatformError $e) {
            $out->error($e->getMessage());

            return 1;
        }

        return 0;
    }
}
