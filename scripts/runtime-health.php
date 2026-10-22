<?php

declare(strict_types=1);

use App\Kernel\Application;
use App\Support\Heartbeat;

require dirname(__DIR__) . '/vendor/autoload.php';

try {
    $app = Application::create(dirname(__DIR__));
    $checks = array_slice($argv, 1);
    if ($checks === ['app']) {
        exit($app->container()->get(\App\Kernel\ReadinessState::class)->ready() ? 0 : 1);
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
