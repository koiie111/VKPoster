<?php

declare(strict_types=1);

namespace App\Domain\Channel;

use App\Integrations\Social\Contracts\Platform;
use App\Integrations\Social\Max\MaxClientFactory;
use App\Integrations\Social\Telegram\TelegramClientFactory;
use App\Kernel\Config;
use Redis;
use Throwable;

/**
 * Facts about our shared bots (Telegram, MAX) for the "connect" pages: whether it is configured and its @username (from the environment,
 * or asked from Telegram once an hour).
 */
final class SharedBot
{
    public function __construct(
        private readonly Config $config,
        private readonly TelegramClientFactory $clients,
        private readonly Redis $redis,
        private readonly MaxClientFactory $maxClients,
    ) {
    }

    public function configured(Platform $platform = Platform::Telegram): bool
    {
        return $this->config->string('platforms.' . $platform->value . '.bot_token') !== '';
    }

    public function username(Platform $platform = Platform::Telegram): ?string
    {
        $key = 'platforms.' . $platform->value;
        $configured = $this->config->string($key . '.bot_username');
        if ($configured !== '') {
            return $configured;
        }
        if (!$this->configured($platform)) {
            return null;
        }
        $cacheKey = 'shared-bot:' . $platform->value . ':username';
        try {
            $cached = $this->redis->get($cacheKey);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
            $token = $this->config->string($key . '.bot_token');
            $me = $platform === Platform::Max ? $this->maxClients->make($token)->getMe() : $this->clients->make($token)->getMe();
            $name = is_string($me['username'] ?? null) ? $me['username'] : null;
            if ($name !== null) {
                $this->redis->setex($cacheKey, 3600, $name);
            }

            return $name;
        } catch (Throwable) {
            return null;
        }
    }
}
