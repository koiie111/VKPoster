<?php

declare(strict_types=1);

namespace App\Domain\Channel;

use App\Integrations\Social\Telegram\TelegramClientFactory;
use App\Kernel\Config;
use Redis;
use Throwable;

/**
 * Facts about our shared Telegram bot for the "connect" page: whether it is configured and its @username (from the environment,
 * or asked from Telegram once an hour).
 */
final class SharedBot
{
    private const CACHE_KEY = 'shared-bot:telegram:username';

    public function __construct(
        private readonly Config $config,
        private readonly TelegramClientFactory $clients,
        private readonly Redis $redis,
    ) {
    }

    public function configured(): bool
    {
        return $this->config->string('platforms.telegram.bot_token') !== '';
    }

    public function username(): ?string
    {
        $configured = $this->config->string('platforms.telegram.bot_username');
        if ($configured !== '') {
            return $configured;
        }
        if (!$this->configured()) {
            return null;
        }
        try {
            $cached = $this->redis->get(self::CACHE_KEY);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
            $me = $this->clients->make($this->config->string('platforms.telegram.bot_token'))->getMe();
            $name = is_string($me['username'] ?? null) ? $me['username'] : null;
            if ($name !== null) {
                $this->redis->setex(self::CACHE_KEY, 3600, $name);
            }

            return $name;
        } catch (Throwable) {
            return null;
        }
    }
}
