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
    'vk' => [
        // The VK ID application that asks for the right to post on community walls. Falls back to the sign-in application.
        'client_id' => $env->string('VK_CLIENT_ID') !== '' ? $env->string('VK_CLIENT_ID') : $env->string('VKID_CLIENT_ID'),
        'client_secret' => $env->string('VK_CLIENT_SECRET') !== '' ? $env->string('VK_CLIENT_SECRET') : $env->string('VKID_CLIENT_SECRET'),
        'scope' => $env->string('VK_SCOPE', 'wall photos video docs groups'),
        'api_version' => '5.199',
        // VK lets a community publish about this many posts a day through the API; the editor warns before it is exceeded.
        'posts_per_day' => $env->int('VK_POSTS_PER_DAY', 50),
    ],
    'max' => [
        // The shared bot every customer adds to their channels (a bot of a verified Russian legal entity, see ADR 0007).
        'bot_token' => $env->string('MAX_BOT_TOKEN'),
        'bot_username' => ltrim($env->string('MAX_BOT_USERNAME'), '@'),
        // Secret part of the webhook URL; the header value MAX echoes back is derived from it.
        'webhook_secret' => $env->string('MAX_WEBHOOK_SECRET'),
        'api_base' => rtrim($env->string('MAX_API_BASE', 'https://platform-api2.max.ru'), '/'),
        // platform-api2.max.ru is signed by the Russian Trusted CA, which the system store lacks: trusted for this client only. Empty disables it.
        'ca_file' => $env->string('MAX_CA_FILE', 'resources/certs/russian-trusted-root-ca.pem'),
    ],
    'instagram' => [
        // The Meta application with the product "Instagram API with Instagram Login" (ADR 0010). Off by default: see PLATFORMS_ENABLED.
        'app_id' => $env->string('INSTAGRAM_APP_ID'),
        'app_secret' => $env->string('INSTAGRAM_APP_SECRET'),
        'scope' => $env->string('INSTAGRAM_SCOPE', 'instagram_business_basic,instagram_business_content_publish,instagram_business_manage_comments'),
        'api_version' => $env->string('INSTAGRAM_API_VERSION', 'v23.0'),
        // Instagram lets an account publish about this many posts a day through the API; the editor warns before it is exceeded.
        'posts_per_day' => $env->int('INSTAGRAM_POSTS_PER_DAY', 100),
    ],
    'channels' => [
        // A global ceiling on all channels of a workspace (paused ones included); the plan's own limit counts active channels only.
        'max_per_workspace' => $env->int('CHANNELS_MAX', 1000),
        'connect_code_ttl' => 900,
        'health_interval_hours' => 6,
        // A channel is checked right before publishing when its last check is older than this many minutes.
        'preflight_minutes' => $env->int('CHANNEL_PREFLIGHT_MINUTES', 15),
    ],
];
