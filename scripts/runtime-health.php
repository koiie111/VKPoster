<?php

declare(strict_types=1);

use App\Kernel\Application;
use App\Kernel\HealthCheck;
use App\Support\Heartbeat;

require dirname(__DIR__) . '/vendor/autoload.php';

try {
    $app = Application::create(dirname(__DIR__));
    $checks = array_slice($argv, 1);
    if ($checks === ['app']) {
        $result = $app->container()->get(HealthCheck::class)->ready(count(\App\Support\Fs::glob(dirname(__DIR__) . '/database/migrations/*.php')));
        exit(in_array('fail', $result, true) ? 1 : 0);
    }
    foreach ($checks as $name) {
        $at = $app->container()->get(Heartbeat::class)->at($name);
        if ($at === null || time() - $at->getTimestamp() > 180) {
            exit(1);
        }
    }
    exit($checks === [] ? 1 : 0);
} catch (Throwable) {
    exit(1);
}
