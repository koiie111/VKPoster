<?php

declare(strict_types=1);

use App\Kernel\Application;

require dirname(__DIR__) . '/vendor/autoload.php';

try {
    $app = Application::create(dirname(__DIR__));
} catch (Throwable $e) {
    // Boot failed (bad configuration): log the reason, show nothing about it.
    error_log('boot failure: ' . $e::class . ': ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Server error';

    return;
}

$app->run();
