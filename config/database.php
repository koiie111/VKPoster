<?php

declare(strict_types=1);

use App\Kernel\Env;

return static fn (Env $env): array => [
    'host' => $env->required('DB_HOST'),
    'port' => $env->int('DB_PORT', 3306),
    'database' => $env->required('DB_DATABASE'),
    'username' => $env->required('DB_USERNAME'),
    'password' => $env->string('DB_PASSWORD'),
    'redis' => [
        'host' => $env->required('REDIS_HOST'),
        'port' => $env->int('REDIS_PORT', 6379),
    ],
];
