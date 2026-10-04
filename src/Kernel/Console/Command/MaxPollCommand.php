<?php

declare(strict_types=1);

namespace App\Kernel\Console\Command;

use App\Domain\Channel\MaxUpdateHandler;
use App\Integrations\Social\Contracts\ErrorKind;
use App\Integrations\Social\Contracts\PlatformError;
use App\Integrations\Social\Max\MaxClientFactory;
use App\Kernel\Config;
use App\Kernel\Console\Command;
use App\Kernel\Console\Output;

/**
 * `max:poll [--once]`: long polling (`GET /updates`) for local development, where MAX cannot reach a webhook. Runs the same handler as
 * the webhook. Removes existing subscriptions first (MAX sends an update either to a webhook or to polling). Stops on SIGTERM/SIGINT.
 * MAX calls polling unfit for production, and so do we.
 */
final class MaxPollCommand implements Command
{
    private bool $stop = false;

    public function __construct(
        private readonly Config $config,
        private readonly MaxClientFactory $clients,
        private readonly MaxUpdateHandler $handler,
    ) {
    }

    public function name(): string
    {
        return 'max:poll';
    }

    public function description(): string
    {
        return 'Receive MAX bot updates by long polling (development, no public HTTPS needed)';
    }

    public function run(array $args, Output $out): int
    {
        $token = $this->config->string('platforms.max.bot_token');
        if ($token === '') {
            $out->error('MAX_BOT_TOKEN is not set.');

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
            foreach ($client->getSubscriptions() as $subscription) {
                if (is_string($subscription['url'] ?? null) && $subscription['url'] !== '') {
                    $client->unsubscribe($subscription['url']);
                }
            }
            $me = $client->getMe();
        } catch (PlatformError $e) {
            $out->error($e->getMessage());

            return 1;
        }
        $out->line('Polling as @' . (is_string($me['username'] ?? null) ? $me['username'] : '?') . '. Press Ctrl+C to stop.');

        $marker = null;
        while (!$this->stop) {
            try {
                $batch = $client->getUpdates($marker, $once ? 0 : 25, MaxUpdateHandler::UPDATE_TYPES);
            } catch (PlatformError $e) {
                $out->error($e->getMessage());
                if ($once || $e->kind === ErrorKind::Auth) {
                    return 1;
                }
                sleep($e->retryAfter ?? 3);
                continue;
            }
            $marker = $batch['marker'] ?? $marker;
            foreach ($batch['updates'] as $update) {
                $this->handler->handle($update);
            }
            if ($batch['updates'] !== []) {
                $out->line(sprintf('Handled %d update(s).', count($batch['updates'])));
            }
            if ($once) {
                break;
            }
        }

        return 0;
    }
}
