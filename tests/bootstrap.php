<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Migrator;

require dirname(__DIR__) . '/vendor/autoload.php';

$env = static function (string $name, string $default): string {
    $value = getenv($name);

    return $value === false || $value === '' ? $default : $value;
};

// Bring the test database (`app_test`) up to date once per test run.
$db = new Connection(
    $env('DB_HOST', 'mysql'),
    (int) $env('DB_PORT', '3306'),
    'app_test',
    $env('DB_USERNAME', 'app'),
    $env('DB_PASSWORD', 'app'),
);
(new Migrator($db, dirname(__DIR__) . '/database/migrations'))->migrate();
