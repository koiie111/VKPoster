<?php

declare(strict_types=1);

use App\Kernel\Env;

return static fn (Env $env): array => [
    'rates' => ['pro' => 60, 'agency' => 300],
    'webhook_failures' => max(1, $env->int('WEBHOOK_FAILURE_LIMIT', 10)),
    'retention_days' => max(35, $env->int('WEBHOOK_RETENTION_DAYS', 35)),
];
