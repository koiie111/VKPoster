<?php

declare(strict_types=1);

use App\Kernel\Env;

return static fn (Env $env): array => [
    // Optional: when set, `/admin` answers 404 to every address outside these IPs / CIDR ranges (comma separated).
    'ip_allowlist' => $env->list('ADMIN_IP_ALLOWLIST'),
];
