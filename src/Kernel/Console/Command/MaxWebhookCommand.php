<?php

declare(strict_types=1);

namespace App\Kernel\Console\Command;

use App\Domain\Channel\MaxUpdateHandler;
use App\Integrations\Social\Contracts\PlatformError;
use App\Integrations\Social\Max\MaxClientFactory;
use App\Integrations\Social\Max\MaxWebhook;
use App\Kernel\Config;
use App\Kernel\Console\Command;
use App\Kernel\Console\Output;

/**
 * `max:webhook set [URL] | delete | info`: point the shared MAX bot at this server. `set` uses `APP_URL` unless given a public base URL
 * (a tunnel). Needs MAX_BOT_TOKEN and MAX_WEBHOOK_SECRET; the secret never gets printed. MAX keeps old subscriptions instead of replacing
 * them, so `set` removes the existing ones first.
 */
final class MaxWebhookCommand implements Command
{
    public function __construct(private readonly Config $config, private readonly MaxClientFactory $clients)
    {
    }

    public function name(): string
    {
        return 'max:webhook';
    }

    public function description(): string
    {
        return 'Manage the shared MAX bot webhook: set [PUBLIC_URL] | delete | info';
    }

    public function run(array $args, Output $out): int
    {
        $token = $this->config->string('platforms.max.bot_token');
        if ($token === '') {
            $out->error('MAX_BOT_TOKEN is not set.');

            return 1;
        }
        $client = $this->clients->make($token);
        $action = $args[0] ?? 'info';
        try {
            if ($action === 'set') {
                $secret = $this->config->string('platforms.max.webhook_secret');
                if (preg_match('/^[A-Za-z0-9_-]{16,128}$/', $secret) !== 1) {
                    $out->error('Set MAX_WEBHOOK_SECRET to 16-128 characters of A-Z a-z 0-9 _ - first, for example: ' . bin2hex(random_bytes(16)));

                    return 1;
                }
                $base = $args[1] ?? $this->config->string('app.url');
                if (!str_starts_with($base, 'https://')) {
                    $out->error('MAX needs a public https:// address on port 443 (use the tunnel profile, or `max:poll` for local development).');

                    return 1;
                }
                $this->removeAll($client);
                $client->subscribe(MaxWebhook::url($base, $secret), MaxUpdateHandler::UPDATE_TYPES, MaxWebhook::headerToken($secret));
                $out->line('Webhook set to ' . rtrim($base, '/') . '/webhooks/max/***');
            } elseif ($action === 'delete') {
                $this->removeAll($client);
                $out->line('Webhook removed.');
            } else {
                $subscriptions = $client->getSubscriptions();
                if ($subscriptions === []) {
                    $out->line('url: (none)');
                }
                foreach ($subscriptions as $subscription) {
                    $url = is_string($subscription['url'] ?? null) ? (string) preg_replace('~/webhooks/max/.*$~', '/webhooks/max/***', $subscription['url']) : '(unknown)';
                    $out->line('url: ' . $url);
                }
            }
        } catch (PlatformError $e) {
            $out->error($e->getMessage());

            return 1;
        }

        return 0;
    }

    /**
     * @throws PlatformError
     */
    private function removeAll(\App\Integrations\Social\Max\MaxClient $client): void
    {
        foreach ($client->getSubscriptions() as $subscription) {
            if (is_string($subscription['url'] ?? null) && $subscription['url'] !== '') {
                $client->unsubscribe($subscription['url']);
            }
        }
    }
}
