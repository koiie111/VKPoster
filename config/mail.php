<?php

declare(strict_types=1);

use App\Kernel\Env;

return static function (Env $env): array {
    // MAIL_DSN wins (e.g. smtp://user:pass@smtp.example.com:587); otherwise plain SMTP to MAIL_HOST:MAIL_PORT.
    $dsn = $env->string('MAIL_DSN');
    if ($dsn === '') {
        $dsn = sprintf('smtp://%s:%d', $env->string('MAIL_HOST', 'mailpit'), $env->int('MAIL_PORT', 1025));
    }

    return [
        'dsn' => $dsn,
        'from' => $env->string('MAIL_FROM', 'no-reply@localhost'),
        // Where "Сообщить о проблеме" messages go (the owner's support mailbox).
        'support' => $env->string('SUPPORT_EMAIL', $env->string('MAIL_FROM', 'no-reply@localhost')),
        'from_name' => $env->string('MAIL_FROM_NAME', $env->string('APP_NAME', 'ezposter')),
    ];
};
