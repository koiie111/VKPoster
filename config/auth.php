<?php

declare(strict_types=1);

use App\Kernel\Env;

return static fn (Env $env): array => [
    // Version of the privacy policy / consent text shown at sign-up; stored with the user's consent.
    'consent_version' => $env->string('CONSENT_VERSION', '2026-10-01'),
    // Look up new passwords in the Have I Been Pwned range API (k-anonymity: only 5 hash characters leave).
    'hibp_enabled' => $env->bool('PASSWORD_HIBP', false),
    // "Remember me" cookie lifetime in days.
    'remember_days' => $env->int('REMEMBER_DAYS', 30),
    // `/dev/login-as/{id}`: on by default in APP_ENV=local, impossible elsewhere (see DevLoginController).
    'dev_login' => $env->bool('DEV_LOGIN', true),
];
