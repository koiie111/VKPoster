<?php

declare(strict_types=1);

use App\Kernel\Env;

/** Dedicated reader authentication; never exposed by a page or used as a Telegram credential. */
return static fn (Env $env): array => [
    // The whole feature (pages, reader ingress, scheduled automation) is off until the owner has decided on the legal and Telegram-account
    // questions of stage 23 and runs the reader in production (ADR 0011).
    'enabled' => $env->bool('SOURCES_ENABLED', false),
    'reader_secret' => $env->string('SOURCES_READER_SECRET'),
];
