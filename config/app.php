<?php

declare(strict_types=1);

use App\Kernel\Env;

return static fn (Env $env): array => [
    'name' => $env->string('APP_NAME', 'ezposter'),
    'env' => $env->string('APP_ENV', 'production'),
    'debug' => $env->bool('APP_DEBUG', false),
    'url' => $env->string('APP_URL', 'http://localhost:8080'),
    'trusted_proxies' => $env->list('TRUSTED_PROXIES'),
    // What is running (shown on the admin system page): set by the deploy, e.g. APP_VERSION=v0.2.0 APP_DEPLOYED_AT=2026-10-05T12:00:00Z.
    'version' => $env->string('APP_VERSION'),
    'deployed_at' => $env->string('APP_DEPLOYED_AT'),
    'log_level' => $env->string('LOG_LEVEL', 'info'),
];
