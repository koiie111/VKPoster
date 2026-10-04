<?php

declare(strict_types=1);

use App\Http\Controllers\Account\LoginMethodsController;
use App\Http\Controllers\Account\SecurityController;
use App\Http\Controllers\Account\TwoFactorController;
use App\Http\Controllers\AppController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\SocialController;
use App\Http\Controllers\Dev\DevOAuthController;
use App\Http\Controllers\Dev\DevLoginController;
use App\Http\Controllers\Dev\DevUiController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\Workspace\AuditController;
use App\Http\Controllers\Workspace\InvitationController;
use App\Http\Controllers\Workspace\SettingsController;
use App\Http\Controllers\Workspace\TeamController;
use App\Http\Controllers\Workspace\WorkspaceController;
use App\Http\Controllers\HomeController;
use App\Http\Middleware\Authenticate;
use App\Http\Middleware\Authorize;
use App\Http\Middleware\Guest;
use App\Http\Middleware\RateLimit;
use App\Http\Middleware\RequireVerifiedEmail;
use App\Http\Middleware\ResolveWorkspace;
use App\Kernel\Http\Router;

return static function (Router $router): void {
    $router->get('/', [HomeController::class, 'index'])->name('home');
    $router->get('/healthz', [HealthController::class, 'show'])->name('health');

    // One-time links carry a 43-character base64url token (see AuthTokens).
    $token = '{token:[A-Za-z0-9_-]{43}}';
    $provider = '{provider:[a-z]{2,10}}';

    // Guests only: signed-in visitors are sent to /app.
    $router->group('', [Guest::class], static function (Router $r) use ($provider): void {
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
        // Social sign-in: `provider` is checked against the enabled providers inside the controller (404 otherwise).
        $r->get('/auth/' . $provider . '/redirect', [SocialController::class, 'redirect'])->middleware([RateLimit::class, ['bucket' => 'oauth-start', 'max' => 60, 'seconds' => 600]]);
        $r->get('/auth/social/consent', [SocialController::class, 'consentShow'])->name('auth.social.consent');
        $r->post('/auth/social/consent', [SocialController::class, 'consentStore'])->middleware([RateLimit::class, ['bucket' => 'oauth-consent', 'max' => 20, 'seconds' => 600]]);
    });

    // The provider sends the browser back here, signed in (account linking) or not.
    $router->get('/auth/' . $provider . '/callback', [SocialController::class, 'callback'])->name('auth.callback')->middleware([RateLimit::class, ['bucket' => 'oauth-callback', 'max' => 30, 'seconds' => 600]]);

    // Links from emails work in any browser, signed in or not.
    $router->get('/password/reset/' . $token, [PasswordResetController::class, 'resetShow'])->name('auth.reset.show');
    $router->post('/password/reset/' . $token, [PasswordResetController::class, 'resetStore'])->middleware([RateLimit::class, ['bucket' => 'reset', 'max' => 10, 'seconds' => 3600]]);
    $router->get('/email/verify/' . $token, [EmailVerificationController::class, 'show'])->name('auth.verify.show');
    $router->post('/email/verify/' . $token, [EmailVerificationController::class, 'confirm'])->middleware([RateLimit::class, ['bucket' => 'verify', 'max' => 20, 'seconds' => 3600]]);
    $router->get('/email/change/' . $token, [SecurityController::class, 'emailConfirmShow'])->name('account.email.confirm.show');
    $router->post('/email/change/' . $token, [SecurityController::class, 'emailConfirm'])->middleware([RateLimit::class, ['bucket' => 'email-change', 'max' => 20, 'seconds' => 3600]]);

    // Signed in; the email may still be unconfirmed.
    $router->group('', [Authenticate::class], static function (Router $r) use ($provider): void {
        $r->post('/logout', [LoginController::class, 'logout'])->name('logout');
        $r->post('/logout/all', [LoginController::class, 'logoutAll'])->name('logout.all');
        $r->get('/email/verification', [EmailVerificationController::class, 'notice'])->name('auth.verify.notice');
        $r->post('/email/verification/resend', [EmailVerificationController::class, 'resend']);

        $r->get('/account/security', [SecurityController::class, 'show'])->name('account.security');
        $r->post('/account/password', [SecurityController::class, 'changePassword']);
        $r->post('/account/email', [SecurityController::class, 'requestEmailChange']);
        $r->post('/account/sessions/{id:[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}}/revoke', [SecurityController::class, 'revokeSession']);
        $r->get('/account/login-methods', [LoginMethodsController::class, 'show'])->name('account.login_methods');
        $r->post('/account/login-methods/password', [LoginMethodsController::class, 'setPassword'])->middleware([RateLimit::class, ['bucket' => 'set-password', 'max' => 10, 'seconds' => 600]]);
        $r->post('/account/login-methods/' . $provider . '/link', [LoginMethodsController::class, 'link']);
        $r->post('/account/login-methods/' . $provider . '/unlink', [LoginMethodsController::class, 'unlink']);
        $r->post('/account/2fa/start', [TwoFactorController::class, 'start']);
        $r->get('/account/2fa/setup', [TwoFactorController::class, 'setup'])->name('account.2fa.setup');
        $r->get('/account/2fa/qr.svg', [TwoFactorController::class, 'qr']);
        $r->post('/account/2fa/confirm', [TwoFactorController::class, 'confirm']);
        $r->post('/account/2fa/disable', [TwoFactorController::class, 'disable']);
        $r->post('/account/2fa/recovery-codes', [TwoFactorController::class, 'regenerateCodes']);
    });

    // The invitation page is public: the link in the email is the secret. Accepting needs an account (see below).
    $router->get('/invitations/' . $token, [InvitationController::class, 'show'])->name('invitation.show');

    // The application itself needs a confirmed email.
    $ulid = '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}';
    $router->group('', [Authenticate::class, RequireVerifiedEmail::class], static function (Router $r) use ($token, $ulid): void {
        $r->get('/app', [AppController::class, 'dashboard'])->name('app');
        $r->get('/workspaces/new', [WorkspaceController::class, 'create'])->name('workspace.new');
        $r->post('/workspaces', [WorkspaceController::class, 'store'])->middleware([RateLimit::class, ['bucket' => 'workspace-create', 'max' => 10, 'seconds' => 3600]]);
        $r->post('/invitations/' . $token . '/accept', [InvitationController::class, 'accept'])->middleware([RateLimit::class, ['bucket' => 'invitation-accept', 'max' => 20, 'seconds' => 3600]]);

        // Everything inside a workspace: `ResolveWorkspace` answers 404 unless the user is a member; `Authorize` checks the role.
        $r->group('/w/{workspaceId:' . $ulid . '}', [ResolveWorkspace::class], static function (Router $w) use ($ulid): void {
            $w->get('', [WorkspaceController::class, 'home'])->name('workspace.home');
            $w->post('/leave', [TeamController::class, 'leave']);

            $w->group('', [[Authorize::class, ['permission' => 'members.manage']]], static function (Router $t) use ($ulid): void {
                $t->get('/team', [TeamController::class, 'show'])->name('workspace.team');
                $t->post('/team/invitations', [TeamController::class, 'invite'])->middleware([RateLimit::class, ['bucket' => 'invite', 'max' => 30, 'seconds' => 3600]]);
                $t->post('/team/invitations/{invitationId:' . $ulid . '}/revoke', [TeamController::class, 'revokeInvitation']);
                $t->post('/team/members/{memberId:' . $ulid . '}/role', [TeamController::class, 'changeRole']);
                $t->post('/team/members/{memberId:' . $ulid . '}/remove', [TeamController::class, 'remove']);
            });
            $w->post('/team/transfer', [TeamController::class, 'transfer'])->middleware([Authorize::class, ['permission' => 'workspace.transfer']]);

            $w->group('', [[Authorize::class, ['permission' => 'workspace.settings']]], static function (Router $t): void {
                $t->get('/settings', [SettingsController::class, 'show'])->name('workspace.settings');
                $t->post('/settings', [SettingsController::class, 'update']);
            });
            $w->post('/delete', [SettingsController::class, 'delete'])->middleware([Authorize::class, ['permission' => 'workspace.delete']]);
            $w->get('/audit', [AuditController::class, 'show'])->name('workspace.audit')->middleware([Authorize::class, ['permission' => 'audit.view']]);
        });
    });

    $router->get('/dev/login-as/{id:[^/]+}', [DevLoginController::class, 'loginAs']);
    // Fake social sign-in provider (404 unless DEV_OAUTH_FAKE is on outside production).
    $router->get('/dev/oauth/fake', [DevOAuthController::class, 'show']);
    $router->get('/dev/oauth/fake/approve', [DevOAuthController::class, 'approve']);

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
