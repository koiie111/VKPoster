<?php

declare(strict_types=1);

use App\Kernel\Env;

/**
 * Social sign-in providers. A provider without its keys is hidden everywhere. `fake` is a built-in
 * stand-in for local development and tests; it can never be enabled in production.
 */
return static fn (Env $env): array => [
    'vkid' => [
        'client_id' => $env->string('VKID_CLIENT_ID'),
        'client_secret' => $env->string('VKID_CLIENT_SECRET'),
    ],
    'yandex' => [
        'client_id' => $env->string('YANDEX_CLIENT_ID'),
        'client_secret' => $env->string('YANDEX_CLIENT_SECRET'),
    ],
    'google' => [
        'client_id' => $env->string('GOOGLE_CLIENT_ID'),
        'client_secret' => $env->string('GOOGLE_CLIENT_SECRET'),
    ],
    'telegram' => [
        'bot_token' => $env->string('TELEGRAM_LOGIN_BOT_TOKEN'),
        'bot_name' => $env->string('TELEGRAM_LOGIN_BOT_NAME'),
    ],
    // Order of the buttons on the sign-in page.
    'order' => $env->list('OAUTH_ORDER') !== [] ? $env->list('OAUTH_ORDER') : ['vkid', 'yandex', 'telegram', 'google'],
    'fake' => $env->bool('DEV_OAUTH_FAKE', false),
];
