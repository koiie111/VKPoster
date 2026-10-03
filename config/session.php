<?php

declare(strict_types=1);

use App\Kernel\Env;

return static fn (Env $env): array => [
    // The cookie is Secure (and named __Host-sid) whenever the app is served over https.
    'secure' => str_starts_with($env->string('APP_URL', 'http://localhost:8080'), 'https://'),
    'idle_ttl' => $env->int('SESSION_IDLE_TTL', 7200),
    'absolute_ttl' => $env->int('SESSION_ABSOLUTE_TTL', 2592000),
];
