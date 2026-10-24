<?php

declare(strict_types=1);

use App\Kernel\Env;
use App\Kernel\Exception\ConfigException;

return static function (Env $env): array {
    $timeout = $env->int('BACKUP_PROCESS_TIMEOUT', 1800);
    if ($timeout < 1 || $timeout > 86400) {
        throw new ConfigException('BACKUP_PROCESS_TIMEOUT must be an integer between 1 and 86400 seconds.');
    }

    return ['backup_process_timeout' => $timeout];
};
