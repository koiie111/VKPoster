<?php

declare(strict_types=1);

// Temporary front controller (stage 00). Replaced by the kernel Application in stage 01.

use App\Kernel\HealthCheck;

require dirname(__DIR__) . '/vendor/autoload.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

header('X-Content-Type-Options: nosniff');

if ($path === '/healthz') {
    $env = [];
    foreach (['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'REDIS_HOST', 'REDIS_PORT'] as $key) {
        $value = getenv($key);
        if ($value !== false) {
            $env[$key] = $value;
        }
    }
    $result = (new HealthCheck($env))->run();
    http_response_code(HealthCheck::isHealthy($result) ? 200 : 503);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($result, JSON_THROW_ON_ERROR);

    return;
}

header('Content-Type: text/plain; charset=utf-8');
echo 'OK';
