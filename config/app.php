<?php

declare(strict_types=1);

use App\Kernel\Env;

return static fn (Env $env): array => [
    'name' => $env->string('APP_NAME', 'ezposter'),
    'env' => $env->string('APP_ENV', 'production'),
    'debug' => $env->bool('APP_DEBUG', false),
    'url' => $env->string('APP_URL', 'http://localhost:8080'),
    'trusted_proxies' => $env->list('TRUSTED_PROXIES'),
    'log_level' => $env->string('LOG_LEVEL', 'info'),
];
