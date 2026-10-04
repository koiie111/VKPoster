<?php

declare(strict_types=1);

use App\Kernel\Env;

/**
 * Social networks and how we talk to them. `enabled` is the feature flag list (PLATFORMS_ENABLED); a platform that is not
 * listed does not appear in the UI and its adapter is never used. `fake` is a stand-in for dev and tests, ignored in production.
 */
return static fn (Env $env): array => [
    'enabled' => $env->list('PLATFORMS_ENABLED') !== [] ? $env->list('PLATFORMS_ENABLED') : ['telegram'],
    'telegram' => [
        // The shared bot every customer adds to their channels.
        'bot_token' => $env->string('TELEGRAM_BOT_TOKEN'),
        'bot_username' => ltrim($env->string('TELEGRAM_BOT_USERNAME'), '@'),
        // Secret part of the webhook URL; the header token Telegram echoes back is derived from it.
        'webhook_secret' => $env->string('TELEGRAM_WEBHOOK_SECRET'),
    ],
    'channels' => [
        'max_per_workspace' => $env->int('CHANNELS_MAX', 100),
        'connect_code_ttl' => 900,
        'health_interval_hours' => 6,
    ],
];
