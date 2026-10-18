<?php

declare(strict_types=1);

use App\Kernel\Env;

/**
 * Links in posts (stage 17). `short_domain`: the host that serves `/<code>` redirects (empty = short links live under `APP_URL/s/<code>`).
 * The visitor hash of a click is salted with the application key and the day, so a person can not be followed from day to day.
 */
return static fn (Env $env): array => [
    'short_domain' => strtolower($env->string('SHORT_LINK_DOMAIN')),
    'manual_per_day' => max(1, min(5000, $env->int('SHORT_LINK_MANUAL_PER_DAY', 100))),
    'click_retention_days' => max(30, min(1500, $env->int('SHORT_LINK_CLICK_RETENTION_DAYS', 400))),
];
