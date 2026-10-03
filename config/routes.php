<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use App\Http\Controllers\HomeController;
use App\Kernel\Http\Router;

return static function (Router $router): void {
    $router->get('/', [HomeController::class, 'index'])->name('home');
    $router->get('/healthz', [HealthController::class, 'show'])->name('health');
};
