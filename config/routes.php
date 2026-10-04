<?php

declare(strict_types=1);

use App\Http\Controllers\Dev\DevUiController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\HomeController;
use App\Kernel\Http\Router;

return static function (Router $router): void {
    $router->get('/', [HomeController::class, 'index'])->name('home');
    $router->get('/healthz', [HealthController::class, 'show'])->name('health');

    // Design-system showcase and prototypes: the controller answers 404 outside APP_ENV=local|testing.
    $router->get('/dev/ui', [DevUiController::class, 'showcase'])->name('dev.ui');
    $router->get('/dev/proto/onboarding', [DevUiController::class, 'onboarding'])->name('dev.onboarding');
    $router->get('/dev/proto/channels', [DevUiController::class, 'channels'])->name('dev.channels');
    $router->get('/dev/proto/editor', [DevUiController::class, 'editor'])->name('dev.editor');
    $router->get('/dev/proto/calendar', [DevUiController::class, 'calendar'])->name('dev.calendar');
    $router->get('/dev/proto/dashboard', [DevUiController::class, 'dashboard'])->name('dev.dashboard');
    $router->get('/dev/layouts/auth', [DevUiController::class, 'layoutAuth'])->name('dev.layout.auth');
    $router->get('/dev/layouts/landing', [DevUiController::class, 'layoutLanding'])->name('dev.layout.landing');
    $router->get('/dev/layouts/admin', [DevUiController::class, 'layoutAdmin'])->name('dev.layout.admin');
};
