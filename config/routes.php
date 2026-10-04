<?php

declare(strict_types=1);

use App\Http\Controllers\Account\SecurityController;
use App\Http\Controllers\Account\TwoFactorController;
use App\Http\Controllers\AppController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Dev\DevLoginController;
use App\Http\Controllers\Dev\DevUiController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\HomeController;
use App\Http\Middleware\Authenticate;
use App\Http\Middleware\Guest;
use App\Http\Middleware\RateLimit;
use App\Http\Middleware\RequireVerifiedEmail;
use App\Kernel\Http\Router;

return static function (Router $router): void {
    $router->get('/', [HomeController::class, 'index'])->name('home');
    $router->get('/healthz', [HealthController::class, 'show'])->name('health');

    // One-time links carry a 43-character base64url token (see AuthTokens).
    $token = '{token:[A-Za-z0-9_-]{43}}';

    // Guests only: signed-in visitors are sent to /app.
    $router->group('', [Guest::class], static function (Router $r): void {
        $r->get('/register', [RegisterController::class, 'show'])->name('register');
        $r->post('/register', [RegisterController::class, 'store'])->middleware([RateLimit::class, ['bucket' => 'register', 'max' => 10, 'seconds' => 3600]]);
        $r->get('/register/done', [RegisterController::class, 'done'])->name('register.done');
        $r->get('/login', [LoginController::class, 'show'])->name('login');
        $r->post('/login', [LoginController::class, 'store'])->middleware([RateLimit::class, ['bucket' => 'login', 'max' => 20, 'seconds' => 600]]);
        $r->get('/login/2fa', [LoginController::class, 'twoFactorShow'])->name('login.2fa');
        $r->post('/login/2fa', [LoginController::class, 'twoFactorStore'])->middleware([RateLimit::class, ['bucket' => 'login-2fa', 'max' => 20, 'seconds' => 600]]);
        $r->get('/password/forgot', [PasswordResetController::class, 'forgotShow'])->name('auth.forgot');
        $r->post('/password/forgot', [PasswordResetController::class, 'forgotStore'])->middleware([RateLimit::class, ['bucket' => 'forgot', 'max' => 10, 'seconds' => 3600]]);
        $r->get('/password/forgot/sent', [PasswordResetController::class, 'forgotSent']);
    });

    // Links from emails work in any browser, signed in or not.
    $router->get('/password/reset/' . $token, [PasswordResetController::class, 'resetShow'])->name('auth.reset.show');
    $router->post('/password/reset/' . $token, [PasswordResetController::class, 'resetStore'])->middleware([RateLimit::class, ['bucket' => 'reset', 'max' => 10, 'seconds' => 3600]]);
    $router->get('/email/verify/' . $token, [EmailVerificationController::class, 'show'])->name('auth.verify.show');
    $router->post('/email/verify/' . $token, [EmailVerificationController::class, 'confirm'])->middleware([RateLimit::class, ['bucket' => 'verify', 'max' => 20, 'seconds' => 3600]]);
    $router->get('/email/change/' . $token, [SecurityController::class, 'emailConfirmShow'])->name('account.email.confirm.show');
    $router->post('/email/change/' . $token, [SecurityController::class, 'emailConfirm'])->middleware([RateLimit::class, ['bucket' => 'email-change', 'max' => 20, 'seconds' => 3600]]);

    // Signed in; the email may still be unconfirmed.
    $router->group('', [Authenticate::class], static function (Router $r): void {
        $r->post('/logout', [LoginController::class, 'logout'])->name('logout');
        $r->post('/logout/all', [LoginController::class, 'logoutAll'])->name('logout.all');
        $r->get('/email/verification', [EmailVerificationController::class, 'notice'])->name('auth.verify.notice');
        $r->post('/email/verification/resend', [EmailVerificationController::class, 'resend']);

        $r->get('/account/security', [SecurityController::class, 'show'])->name('account.security');
        $r->post('/account/password', [SecurityController::class, 'changePassword']);
        $r->post('/account/email', [SecurityController::class, 'requestEmailChange']);
        $r->post('/account/sessions/{id:[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}}/revoke', [SecurityController::class, 'revokeSession']);
        $r->post('/account/2fa/start', [TwoFactorController::class, 'start']);
        $r->get('/account/2fa/setup', [TwoFactorController::class, 'setup'])->name('account.2fa.setup');
        $r->get('/account/2fa/qr.svg', [TwoFactorController::class, 'qr']);
        $r->post('/account/2fa/confirm', [TwoFactorController::class, 'confirm']);
        $r->post('/account/2fa/disable', [TwoFactorController::class, 'disable']);
        $r->post('/account/2fa/recovery-codes', [TwoFactorController::class, 'regenerateCodes']);
    });

    // The application itself needs a confirmed email.
    $router->group('', [Authenticate::class, RequireVerifiedEmail::class], static function (Router $r): void {
        $r->get('/app', [AppController::class, 'dashboard'])->name('app');
    });

    $router->get('/dev/login-as/{id:[^/]+}', [DevLoginController::class, 'loginAs']);

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
