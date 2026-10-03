<?php

declare(strict_types=1);

use App\Kernel\Env;

return static function (Env $env): array {
    // APP_KEY: current key (base64, 32 bytes). APP_KEYS: older keys kept only for decryption,
    // "id:base64,id:base64". See docs/architecture/security.md for the rotation procedure.
    $old = [];
    foreach ($env->list('APP_KEYS') as $entry) {
        [$id, $key] = array_pad(explode(':', $entry, 2), 2, '');
        $old[$id] = $key;
    }

    return [
        'key_id' => $env->string('APP_KEY_ID', 'k1'),
        'key' => $env->required('APP_KEY'),
        'old_keys' => $old,
        'argon' => [
            'memory_kib' => $env->int('ARGON_MEMORY_KIB', 65536),
            'time_cost' => $env->int('ARGON_TIME_COST', 4),
        ],
    ];
};
