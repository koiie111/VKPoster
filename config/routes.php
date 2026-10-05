<?php

declare(strict_types=1);

use App\Http\Controllers\Account\ConsentController;
use App\Http\Controllers\Account\FeedbackController;
use App\Http\Controllers\Account\LoginMethodsController;
use App\Http\Controllers\Account\NotificationController;
use App\Http\Controllers\Account\SecurityController;
use App\Http\Controllers\Account\TwoFactorController;
use App\Http\Controllers\AppController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\SocialController;
use App\Http\Controllers\Billing\BillingController;
use App\Http\Controllers\Dev\DevOAuthController;
use App\Http\Controllers\Dev\FakePaymentController;
use App\Http\Controllers\Dev\DevLoginController;
use App\Http\Controllers\Dev\DevUiController;
use App\Http\Controllers\Channels\ChannelController;
use App\Http\Controllers\Channels\MaxConnectController;
use App\Http\Controllers\Channels\VkConnectController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\Webhooks\MaxWebhookController;
use App\Http\Controllers\Webhooks\PaymentWebhookController;
use App\Http\Controllers\Webhooks\TelegramWebhookController;
use App\Http\Controllers\Workspace\AuditController;
use App\Http\Controllers\Workspace\InvitationController;
use App\Http\Controllers\Workspace\SettingsController;
use App\Http\Controllers\Workspace\TeamController;
use App\Http\Controllers\Workspace\WorkspaceController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Site\HelpController;
use App\Http\Controllers\Site\LegalController;
use App\Http\Controllers\Site\SeoController;
use App\Http\Controllers\Site\StatusController;
use App\Http\Controllers\Media\FolderController;
use App\Http\Controllers\Media\MediaController;
use App\Http\Controllers\Media\MediaFileController;
use App\Http\Controllers\Media\PickerController;
use App\Http\Controllers\Posts\CalendarController;
use App\Http\Controllers\Posts\PostController;
use App\Http\Controllers\Posts\TemplateController;
use App\Http\Controllers\Media\WatermarkController;
use App\Http\Middleware\AuthenticateOrSigned;
use App\Http\Middleware\Authenticate;
use App\Http\Middleware\Authorize;
use App\Http\Middleware\Guest;
use App\Http\Middleware\OptionalAuthenticate;
use App\Http\Middleware\RateLimit;
use App\Http\Middleware\RequireConsent;
use App\Http\Middleware\RequireVerifiedEmail;
use App\Http\Middleware\ResolveWorkspace;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\BillingAdminController;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Admin\OperationsController;
use App\Http\Controllers\Admin\UsersController;
use App\Http\Controllers\Admin\WorkspacesController;
use App\Http\Middleware\DenyWhenImpersonating;
use App\Http\Middleware\RequireAdminUnlock;
use App\Http\Middleware\RequireStaff;
use App\Kernel\Http\Router;

return static function (Router $router): void {
    $router->get('/', [HomeController::class, 'index'])->name('home')->middleware(OptionalAuthenticate::class);
    $router->get('/healthz', [HealthController::class, 'show'])->name('health');

    // Public site: legal documents, the knowledge base, the status of the networks, and what search engines may read.
    $router->get('/legal/{slug:[a-z]{3,20}}', [LegalController::class, 'show'])->name('legal.show')->middleware(OptionalAuthenticate::class);
    $router->get('/help', [HelpController::class, 'index'])->name('help')->middleware(OptionalAuthenticate::class);
    $router->get('/help/{slug:[a-z0-9-]{1,60}}', [HelpController::class, 'show'])->name('help.show')->middleware(OptionalAuthenticate::class);
    $router->get('/status', [StatusController::class, 'show'])->name('status')->middleware(OptionalAuthenticate::class);
    $router->get('/robots.txt', [SeoController::class, 'robots']);
    $router->get('/sitemap.xml', [SeoController::class, 'sitemap']);

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
        $r->post('/logout/all', [LoginController::class, 'logoutAll'])->name('logout.all')->middleware(DenyWhenImpersonating::class);
        $r->post('/impersonation/stop', [ImpersonationController::class, 'stop']);
        $r->get('/feedback', [FeedbackController::class, 'show'])->name('feedback');
        $r->post('/feedback', [FeedbackController::class, 'send'])->middleware([RateLimit::class, ['bucket' => 'feedback', 'max' => 10, 'seconds' => 3600]]);
        $r->get('/consent', [ConsentController::class, 'show'])->name('consent');
        $r->post('/consent', [ConsentController::class, 'accept'])->middleware([RateLimit::class, ['bucket' => 'consent', 'max' => 30, 'seconds' => 600]]);
        $r->get('/email/verification', [EmailVerificationController::class, 'notice'])->name('auth.verify.notice');
        $r->post('/email/verification/resend', [EmailVerificationController::class, 'resend']);

        // Security of the account is the person's own: closed while support acts as them.
        $r->group('', [DenyWhenImpersonating::class], static function (Router $a) use ($provider): void {
            $a->get('/account/security', [SecurityController::class, 'show'])->name('account.security');
            $a->post('/account/password', [SecurityController::class, 'changePassword']);
            $a->post('/account/email', [SecurityController::class, 'requestEmailChange']);
            $a->post('/account/sessions/{id:[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}}/revoke', [SecurityController::class, 'revokeSession']);
            $a->get('/account/login-methods', [LoginMethodsController::class, 'show'])->name('account.login_methods');
            $a->post('/account/login-methods/password', [LoginMethodsController::class, 'setPassword'])->middleware([RateLimit::class, ['bucket' => 'set-password', 'max' => 10, 'seconds' => 600]]);
            $a->post('/account/login-methods/' . $provider . '/link', [LoginMethodsController::class, 'link']);
            $a->post('/account/login-methods/' . $provider . '/unlink', [LoginMethodsController::class, 'unlink']);
            $a->get('/account/notifications', [NotificationController::class, 'show'])->name('account.notifications');
            $a->post('/account/notifications', [NotificationController::class, 'save']);
            $a->post('/account/notifications/telegram/link', [NotificationController::class, 'linkTelegram'])->middleware([RateLimit::class, ['bucket' => 'telegram-link', 'max' => 10, 'seconds' => 3600]]);
            $a->post('/account/notifications/telegram/unlink', [NotificationController::class, 'unlinkTelegram']);
            $a->post('/account/2fa/start', [TwoFactorController::class, 'start']);
            $a->get('/account/2fa/setup', [TwoFactorController::class, 'setup'])->name('account.2fa.setup');
            $a->get('/account/2fa/qr.svg', [TwoFactorController::class, 'qr']);
            $a->post('/account/2fa/confirm', [TwoFactorController::class, 'confirm']);
            $a->post('/account/2fa/disable', [TwoFactorController::class, 'disable']);
            $a->post('/account/2fa/recovery-codes', [TwoFactorController::class, 'regenerateCodes']);
        });
    });

    // The invitation page is public: the link in the email is the secret. Accepting needs an account (see below).
    $router->get('/invitations/' . $token, [InvitationController::class, 'show'])->name('invitation.show');

    // The application itself needs a confirmed email.
    $ulid = '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}';
    $router->group('', [Authenticate::class, RequireVerifiedEmail::class, RequireConsent::class], static function (Router $r) use ($token, $ulid): void {
        $r->get('/app', [AppController::class, 'dashboard'])->name('app');
        $r->get('/workspaces/new', [WorkspaceController::class, 'create'])->name('workspace.new');
        // VK ID sends the browser back here (a fixed address registered in the VK application); the controller checks the workspace and the right.
        $r->get('/channels/connect/vk/callback', [VkConnectController::class, 'callback'])->middleware([RateLimit::class, ['bucket' => 'vk-callback', 'max' => 30, 'seconds' => 600]]);
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
            $w->post('/team/transfer', [TeamController::class, 'transfer'])->middleware([Authorize::class, ['permission' => 'workspace.transfer']], DenyWhenImpersonating::class);

            $w->group('', [[Authorize::class, ['permission' => 'workspace.settings']]], static function (Router $t): void {
                $t->get('/settings', [SettingsController::class, 'show'])->name('workspace.settings');
                $t->post('/settings', [SettingsController::class, 'update']);
            });
            $w->post('/delete', [SettingsController::class, 'delete'])->middleware([Authorize::class, ['permission' => 'workspace.delete']], DenyWhenImpersonating::class);
            // Plan and payment: the owner only. `pay` sends the browser on to the provider (a plain page load, see BillingController::checkout).
            $w->group('/billing', [[Authorize::class, ['permission' => 'workspace.billing']], DenyWhenImpersonating::class], static function (Router $b) use ($ulid): void {
                $b->get('', [BillingController::class, 'overview'])->name('workspace.billing');
                $b->get('/plans', [BillingController::class, 'plans'])->name('workspace.billing.plans');
                $b->post('/checkout', [BillingController::class, 'checkout'])->middleware([RateLimit::class, ['bucket' => 'billing-checkout', 'max' => 20, 'seconds' => 3600]]);
                $b->get('/pay/{paymentId:' . $ulid . '}', [BillingController::class, 'pay'])->middleware([RateLimit::class, ['bucket' => 'billing-pay', 'max' => 60, 'seconds' => 3600]]);
                $b->get('/return', [BillingController::class, 'returned'])->middleware([RateLimit::class, ['bucket' => 'billing-return', 'max' => 120, 'seconds' => 600]]);
                $b->post('/renewal/cancel', [BillingController::class, 'cancelRenewal']);
                $b->post('/renewal/resume', [BillingController::class, 'resumeRenewal']);
                $b->post('/change/cancel', [BillingController::class, 'unschedule']);
                $b->post('/card/remove', [BillingController::class, 'forgetCard']);
                $b->get('/invoices/{invoiceId:' . $ulid . '}/receipt', [BillingController::class, 'receipt'])->name('workspace.billing.receipt');
            });
            // Media library. Viewing: everyone who works on posts; uploading: authors and up; changes: editors and up.
            $w->group('', [[Authorize::class, ['permission' => 'media.view']]], static function (Router $m) use ($ulid): void {
                $m->get('/media', [MediaController::class, 'index'])->name('workspace.media');
                $m->get('/media/{mediaId:' . $ulid . '}', [MediaController::class, 'show'])->name('workspace.media.show');
            });
            $w->group('', [[Authorize::class, ['permission' => 'media.upload']]], static function (Router $m): void {
                $m->post('/media/upload', [MediaController::class, 'upload'])->middleware([RateLimit::class, ['bucket' => 'media-upload', 'max' => 300, 'seconds' => 600]]);
                $m->post('/media/upload-url', [MediaController::class, 'uploadUrl'])->middleware([RateLimit::class, ['bucket' => 'media-url', 'max' => 30, 'seconds' => 600]]);
            });
            $w->group('', [[Authorize::class, ['permission' => 'media.manage']]], static function (Router $m) use ($ulid): void {
                $item = '/media/{mediaId:' . $ulid . '}';
                $m->post($item . '/rename', [MediaController::class, 'rename']);
                $m->post($item . '/move', [MediaController::class, 'move']);
                $m->post($item . '/delete', [MediaController::class, 'delete']);
                $m->post('/media/folders', [FolderController::class, 'create']);
                $m->post('/media/folders/{folderId:' . $ulid . '}/rename', [FolderController::class, 'rename']);
                $m->post('/media/folders/{folderId:' . $ulid . '}/delete', [FolderController::class, 'delete']);
                $m->get('/media/watermarks', [WatermarkController::class, 'show'])->name('workspace.media.watermarks');
                $m->post('/media/watermarks', [WatermarkController::class, 'create'])->middleware([RateLimit::class, ['bucket' => 'watermark-upload', 'max' => 20, 'seconds' => 600]]);
                $mark = '/media/watermarks/{watermarkId:' . $ulid . '}';
                $m->get($mark . '/logo', [WatermarkController::class, 'logo']);
                $m->get($mark . '/preview', [WatermarkController::class, 'preview'])->middleware([RateLimit::class, ['bucket' => 'watermark-preview', 'max' => 120, 'seconds' => 60]]);
                $m->post($mark . '/update', [WatermarkController::class, 'update']);
                $m->post($mark . '/delete', [WatermarkController::class, 'delete']);
            });
            // Channels. Everyone who works on posts may look; connecting and changing is for owners and administrators.
            $w->get('/channels', [ChannelController::class, 'index'])->name('workspace.channels')->middleware([Authorize::class, ['permission' => 'channels.view']]);
            $w->get('/channels/{channelId:' . $ulid . '}/avatar', [ChannelController::class, 'avatar'])->middleware([Authorize::class, ['permission' => 'channels.view']]);
            $w->group('/channels', [[Authorize::class, ['permission' => 'channels.manage']]], static function (Router $c) use ($ulid): void {
                $c->get('/connect/vk', [VkConnectController::class, 'show']);
                $c->get('/connect/vk/start', [VkConnectController::class, 'start'])->middleware([RateLimit::class, ['bucket' => 'vk-connect', 'max' => 30, 'seconds' => 3600]]);
                $c->get('/connect/vk/choose', [VkConnectController::class, 'choose']);
                $c->post('/connect/vk/choose', [VkConnectController::class, 'connect'])->middleware([RateLimit::class, ['bucket' => 'vk-choose', 'max' => 30, 'seconds' => 3600]]);
                $c->get('/connect/telegram', [ChannelController::class, 'connectTelegram']);
                $c->post('/connect/telegram/code', [ChannelController::class, 'issueCode'])->middleware([RateLimit::class, ['bucket' => 'channel-code', 'max' => 20, 'seconds' => 3600]]);
                $c->get('/connect/telegram/status/{codeId:' . $ulid . '}', [ChannelController::class, 'codeStatus'])->middleware([RateLimit::class, ['bucket' => 'channel-code-status', 'max' => 600, 'seconds' => 600]]);
                $c->post('/connect/telegram/own', [ChannelController::class, 'connectOwn'])->middleware([RateLimit::class, ['bucket' => 'channel-own-bot', 'max' => 15, 'seconds' => 3600]]);
                $c->get('/connect/max', [MaxConnectController::class, 'show']);
                $c->post('/connect/max/code', [MaxConnectController::class, 'issueCode'])->middleware([RateLimit::class, ['bucket' => 'channel-code', 'max' => 20, 'seconds' => 3600]]);
                $c->get('/connect/max/status/{codeId:' . $ulid . '}', [MaxConnectController::class, 'codeStatus'])->middleware([RateLimit::class, ['bucket' => 'channel-code-status', 'max' => 600, 'seconds' => 600]]);
                $c->post('/connect/max/own', [MaxConnectController::class, 'connectOwn'])->middleware([RateLimit::class, ['bucket' => 'channel-own-bot', 'max' => 15, 'seconds' => 3600]]);
                // The test network: the controller answers 404 unless it is enabled (never in production).
                $c->get('/connect/fake', [ChannelController::class, 'fakeForm']);
                $c->post('/connect/fake', [ChannelController::class, 'connectFake']);
                $item = '/{channelId:' . $ulid . '}';
                $c->post($item . '/check', [ChannelController::class, 'check'])->middleware([RateLimit::class, ['bucket' => 'channel-check', 'max' => 60, 'seconds' => 600]]);
                $c->post($item . '/pause', [ChannelController::class, 'pause']);
                $c->post($item . '/resume', [ChannelController::class, 'resume']);
                $c->post($item . '/rename', [ChannelController::class, 'rename']);
                $c->post($item . '/delete', [ChannelController::class, 'delete']);
            });
            // Posts. Looking (the calendar and a post's page): everyone with calendar access; writing drafts: authors and up;
            // planning, moving, cancelling, retrying: editors and up (`PostService` repeats the check, and adds the per-post rules).
            $w->group('', [[Authorize::class, ['permission' => 'calendar.view']]], static function (Router $p) use ($ulid): void {
                $p->get('/calendar', [CalendarController::class, 'show'])->name('workspace.calendar');
                $p->get('/posts/{postId:' . $ulid . '}', [PostController::class, 'show'])->name('workspace.post');
            });
            $w->group('', [[Authorize::class, ['permission' => 'posts.draft']]], static function (Router $p) use ($ulid): void {
                $p->get('/posts/new', [PostController::class, 'create'])->name('workspace.post.new');
                $p->post('/posts', [PostController::class, 'store'])->middleware([RateLimit::class, ['bucket' => 'post-save', 'max' => 120, 'seconds' => 600]]);
                $p->post('/posts/autosave', [PostController::class, 'autosave'])->middleware([RateLimit::class, ['bucket' => 'post-autosave', 'max' => 600, 'seconds' => 600]]);
                $p->post('/posts/validate', [PostController::class, 'validate'])->middleware([RateLimit::class, ['bucket' => 'post-validate', 'max' => 600, 'seconds' => 600]]);
                $p->post('/posts/preview', [PostController::class, 'preview'])->middleware([RateLimit::class, ['bucket' => 'post-preview', 'max' => 600, 'seconds' => 600]]);
                $item = '/posts/{postId:' . $ulid . '}';
                $p->get($item . '/edit', [PostController::class, 'edit']);
                $p->post($item, [PostController::class, 'update'])->middleware([RateLimit::class, ['bucket' => 'post-save', 'max' => 120, 'seconds' => 600]]);
                $p->post($item . '/duplicate', [PostController::class, 'duplicate']);
                $p->post($item . '/delete', [PostController::class, 'delete']);
                $p->get('/templates', [TemplateController::class, 'index'])->name('workspace.templates');
                $p->post('/templates', [TemplateController::class, 'store']);
                $p->post('/templates/{templateId:' . $ulid . '}/delete', [TemplateController::class, 'delete']);
            });
            $w->group('', [[Authorize::class, ['permission' => 'posts.publish']]], static function (Router $p) use ($ulid): void {
                $item = '/posts/{postId:' . $ulid . '}';
                $p->post($item . '/move', [PostController::class, 'move'])->middleware([RateLimit::class, ['bucket' => 'post-move', 'max' => 300, 'seconds' => 600]]);
                $p->post($item . '/cancel', [PostController::class, 'cancel']);
                $p->post($item . '/edit-published', [PostController::class, 'editPublished']);
                $p->post($item . '/publications/{publicationId:' . $ulid . '}/retry', [PostController::class, 'retry']);
                $p->post($item . '/publications/{publicationId:' . $ulid . '}/settle', [PostController::class, 'settle']);
                $p->post($item . '/publications/{publicationId:' . $ulid . '}/remove', [PostController::class, 'removeFromNetwork']);
            });
            $w->get('/media-picker', [PickerController::class, 'list'])->middleware([Authorize::class, ['permission' => 'posts.draft']]);
            $w->get('/audit', [AuditController::class, 'show'])->name('workspace.audit')->middleware([Authorize::class, ['permission' => 'audit.view']]);
        });
    });

    // The back office. Superadmins only (anybody else gets 404), two-factor protected, with a fresh code every 8 hours and its own rate limit.
    $router->group('/admin', [Authenticate::class, RequireVerifiedEmail::class, [RateLimit::class, ['bucket' => 'admin', 'max' => 600, 'seconds' => 600]], RequireStaff::class], static function (Router $a): void {
        $a->get('/unlock', [AdminController::class, 'unlockShow'])->name('admin.unlock');
        $a->post('/unlock', [AdminController::class, 'unlock'])->middleware([RateLimit::class, ['bucket' => 'admin-unlock', 'max' => 10, 'seconds' => 600]]);
        $a->group('', [RequireAdminUnlock::class], static function (Router $s): void {
            $s->get('', [AdminController::class, 'overview'])->name('admin');
            $s->get('/users', [UsersController::class, 'index'])->name('admin.users');
            $s->get('/users/{id:[0-9]{1,12}}', [UsersController::class, 'show']);
            $s->post('/users/{id:[0-9]{1,12}}/block', [UsersController::class, 'block']);
            $s->post('/users/{id:[0-9]{1,12}}/unblock', [UsersController::class, 'unblock']);
            $s->post('/users/{id:[0-9]{1,12}}/impersonate', [UsersController::class, 'impersonate'])->middleware([RateLimit::class, ['bucket' => 'admin-impersonate', 'max' => 30, 'seconds' => 3600]]);
            $s->get('/workspaces', [WorkspacesController::class, 'index'])->name('admin.workspaces');
            $s->get('/workspaces/{publicId:' . '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}' . '}', [WorkspacesController::class, 'show']);
            $s->post('/workspaces/{publicId:' . '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}' . '}/grant', [WorkspacesController::class, 'grant']);
            $s->get('/subscriptions', [BillingAdminController::class, 'subscriptions'])->name('admin.subscriptions');
            $s->get('/payments', [BillingAdminController::class, 'payments'])->name('admin.payments');
            $s->post('/payments/{paymentId:' . '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}' . '}/refund', [BillingAdminController::class, 'refund'])->middleware([RateLimit::class, ['bucket' => 'admin-refund', 'max' => 30, 'seconds' => 3600]]);
            $s->get('/plans', [BillingAdminController::class, 'plans'])->name('admin.plans');
            $s->get('/plans/{code:[a-z0-9_]{1,32}}', [BillingAdminController::class, 'editPlan']);
            $s->post('/plans/{code:[a-z0-9_]{1,32}}', [BillingAdminController::class, 'updatePlan']);
            $s->get('/promo', [OperationsController::class, 'promo'])->name('admin.promo');
            $s->get('/queues', [OperationsController::class, 'queues'])->name('admin.queues');
            $s->post('/queues/failed/{id:[0-9]{1,12}}/retry', [OperationsController::class, 'retry']);
            $s->post('/queues/failed/{id:[0-9]{1,12}}/discard', [OperationsController::class, 'discard']);
            $s->get('/channels', [OperationsController::class, 'channels'])->name('admin.channels');
            $s->get('/platforms', [OperationsController::class, 'platforms'])->name('admin.platforms');
            $s->post('/platforms', [OperationsController::class, 'savePlatforms']);
        });
    });

    // Library files: a signed-in member of the owning workspace, or a signed expiring link (checked in the controller).
    $router->get('/media/{id:' . $ulid . '}/{variant:[a-z0-9-]{1,20}}', [MediaFileController::class, 'show'])->name('media.file')->middleware(AuthenticateOrSigned::class);

    // Telegram calls this for every update of the shared bot: authenticity is the secret in the path plus a header (see TelegramWebhook).
    $router->post('/webhooks/telegram/{secret:[A-Za-z0-9_-]{16,128}}', [TelegramWebhookController::class, 'receive'])->withoutCsrf();
    // MAX calls this for every update of the shared bot: the secret in the path plus the X-Max-Bot-Api-Secret header (see MaxWebhook).
    $router->post('/webhooks/max/{secret:[A-Za-z0-9_-]{16,128}}', [MaxWebhookController::class, 'receive'])->withoutCsrf();

    // Payment providers call this for every payment event: authenticity is checked by the provider's gateway before anything is read.
    $router->post('/webhooks/billing/{provider:[a-z]{3,12}}', [PaymentWebhookController::class, 'receive'])->withoutCsrf();

    $router->get('/dev/login-as/{id:[^/]+}', [DevLoginController::class, 'loginAs']);
    // The payment page of the test provider (404 outside local/testing).
    $router->get('/dev/billing/pay/{paymentId:' . $ulid . '}', [FakePaymentController::class, 'show']);
    $router->post('/dev/billing/pay/{paymentId:' . $ulid . '}', [FakePaymentController::class, 'answer']);
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
