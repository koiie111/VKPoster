<?php

declare(strict_types=1);

use App\Domain\Auth\PasswordPolicy;
use App\Domain\Billing\Entitlements;
use App\Domain\Help\HelpArticles;
use App\Domain\Legal\LegalDocuments;
use App\Domain\Settings\Settings;
use App\Domain\Billing\PriceCalculator;
use App\Integrations\Payments\GatewayFactory;
use App\Integrations\Payments\GatewayRegistry;
use App\Domain\Channel\ConnectCodes;
use App\Integrations\Social\Fake\FakeAdapter;
use App\Integrations\Social\PlatformRegistry;
use App\Integrations\Social\Instagram\InstagramAdapter;
use App\Integrations\Social\Instagram\InstagramApi;
use App\Integrations\Social\Instagram\InstagramOAuth;
use App\Integrations\Social\Max\MaxAdapter;
use App\Integrations\Social\Max\MaxClientFactory;
use App\Integrations\Social\Max\MaxRateGate;
use App\Integrations\Social\Telegram\TelegramAdapter;
use App\Integrations\Social\Vk\VkAdapter;
use App\Integrations\Social\Vk\VkApi;
use App\Integrations\Social\Vk\VkCommunities;
use App\Integrations\Social\Vk\VkOAuth;
use App\Integrations\Social\Vk\VkRateGate;
use App\Integrations\Social\Telegram\TelegramClientFactory;
use App\Domain\Media\FfprobeVideoProbe;
use App\Domain\Media\FolderRepository;
use App\Domain\Media\ImageProcessor;
use App\Domain\Media\MediaLimits;
use App\Domain\Media\MediaRepository;
use App\Domain\Media\MediaService;
use App\Domain\Media\MediaUsageChecker;
use App\Domain\Post\PostUsageChecker;
use App\Domain\Media\VideoProbe;
use App\Integrations\Storage\MediaStorage;
use App\Integrations\Storage\MediaStorageFactory;
use App\Domain\Workspace\Permissions;
use App\Http\WorkspaceNav;
use App\Domain\Auth\RegistrationService;
use App\Domain\Auth\RememberMe;
use App\Kernel\Config;
use App\Kernel\Console\Command\SeedCommand;
use App\Kernel\Container;
use App\Kernel\Database\Connection;
use App\Kernel\Database\Migrator;
use App\Kernel\HealthCheck;
use App\Kernel\Http\RequestContext;
use App\Kernel\Http\Router;
use App\Kernel\HttpClient\GuzzleHttpClient;
use App\Kernel\HttpClient\HttpClientInterface;
use App\Kernel\Log\LoggerFactory;
use App\Kernel\Mail\Mailer;
use App\Kernel\Mail\SymfonyMailer;
use App\Kernel\Queue\Schedule;
use App\Kernel\Security\Crypto;
use App\Kernel\Security\Csrf;
use App\Kernel\Security\PasswordHasher;
use App\Kernel\Session\RedisSessionStore;
use App\Kernel\Session\SessionStore;
use App\Kernel\View\Translator;
use App\Kernel\View\View;
use App\Support\Clock;
use App\Support\SystemClock;
use Psr\Log\LoggerInterface;

/**
 * Explicit service wiring: everything the autowirer cannot infer from type hints
 * (scalars, interfaces, shared resources). `$base` is the project root.
 */
return static function (Container $c, string $base): void {
    $c->factory(Clock::class, static fn (): Clock => new SystemClock());

    $c->factory(LoggerInterface::class, static function (Container $c) use ($base): LoggerInterface {
        $config = $c->get(Config::class);

        return LoggerFactory::create(
            $base . '/storage/logs',
            $config->string('app.log_level', 'info'),
            !$config->env()->bool('LOG_DISABLE_STDERR'),
            $config->env()->has('SENTRY_DSN') ? new \App\Kernel\Operations\GlitchTipReporter(new GuzzleHttpClient(new \App\Kernel\HttpClient\SsrfGuard()), $config, $c->get(Clock::class)) : null,
        );
    });

    $c->factory(Connection::class, static function (Container $c): Connection {
        $config = $c->get(Config::class);

        return new Connection(
            $config->string('database.host'),
            $config->int('database.port', 3306),
            $config->string('database.database'),
            $config->string('database.username'),
            $config->string('database.password'),
        );
    });

    $c->factory(Migrator::class, static fn (Container $c): Migrator => new Migrator($c->get(Connection::class), $base . '/database/migrations'));

    $c->factory(\Redis::class, static function (Container $c): \Redis {
        $config = $c->get(Config::class);
        $redis = new \Redis();
        $redis->connect($config->string('database.redis.host'), $config->int('database.redis.port', 6379), 2.0);
        if ($config->string('database.redis.password') !== '') {
            $redis->auth($config->string('database.redis.password'));
        }
        $redis->select($config->int('database.redis.db', 0));

        return $redis;
    });

    $c->factory(SessionStore::class, static fn (Container $c): SessionStore => new RedisSessionStore($c->get(\Redis::class)));

    $c->factory(\App\Kernel\Security\IsolatedCrypto::class, static function (Container $c): \App\Kernel\Security\IsolatedCrypto {
        $env = $c->get(Config::class)->env();
        return new \App\Kernel\Security\VaultTransit(
            $c->get(HttpClientInterface::class),
            $env->required('VAULT_TRANSIT_URL'),
            $env->string('VAULT_TRANSIT_KEY', 'vkposter'),
            $env->required('VAULT_TOKEN_FILE'),
            $env->string('VAULT_CA_FILE'),
            $env->string('VAULT_LEGACY_TRANSIT_KEY'),
            $env->string('VAULT_LEGACY_SIGNER_KEY'),
            $env->int('VAULT_LEGACY_SIGNER_UNTIL'),
            $c->get(Clock::class)
        );
    });
    $c->factory(Crypto::class, static function (Container $c): Crypto {
        $config = $c->get(Config::class);
        if ($config->env()->string('CRYPTO_BACKEND', 'sodium') === 'vault') {
            return new Crypto('isolated', [], $c->get(\App\Kernel\Security\IsolatedCrypto::class));
        }
        $keys = [$config->string('security.key_id') => $config->string('security.key')];
        foreach ($config->array('security.old_keys') as $id => $key) {
            $keys[(string) $id] = is_string($key) ? $key : '';
        }
        return new Crypto($config->string('security.key_id'), $keys);
    });

    $c->factory(\App\Domain\Analytics\AnalyticsPdf::class, static fn (Container $c): \App\Domain\Analytics\AnalyticsPdf => new \App\Domain\Analytics\AnalyticsPdf(
        $c->get(HttpClientInterface::class),
        $c->get(Config::class),
        $c->get(Crypto::class)
    ));

    $c->factory(PasswordHasher::class, static function (Container $c): PasswordHasher {
        $config = $c->get(Config::class);

        $env = $config->env();
        $previous = [];
        foreach ($env->list('PASSWORD_PEPPER_PREVIOUS_FILES') as $entry) {
            [$id, $file] = array_pad(explode(':', $entry, 2), 2, '');
            $previous[$id] = \App\Kernel\Security\SecretKeyFile::read($file);
        }
        $file = $env->string('PASSWORD_PEPPER_FILE');
        return new PasswordHasher(
            $config->int('security.argon.memory_kib', 65536),
            $config->int('security.argon.time_cost', 4),
            1,
            $file === '' ? null : \App\Kernel\Security\SecretKeyFile::read($file),
            $env->string('PASSWORD_PEPPER_ID', 'p1'),
            $previous,
            $env->string('CRYPTO_BACKEND', 'sodium') === 'vault' ? $c->get(\App\Kernel\Security\IsolatedCrypto::class) : null
        );
    });

    $c->factory(Mailer::class, static function (Container $c): Mailer {
        $config = $c->get(Config::class);

        return new SymfonyMailer($config->string('mail.dsn'), $config->string('mail.from'), $config->string('mail.from_name'));
    });

    $c->factory(PasswordPolicy::class, static function (Container $c) use ($base): PasswordPolicy {
        return new PasswordPolicy(
            $c->get(HttpClientInterface::class),
            $c->get(LoggerInterface::class),
            $c->get(Config::class)->bool('auth.hibp_enabled'),
            $base . '/resources/data/common-passwords.txt',
        );
    });

    $c->factory(RememberMe::class, static fn (Container $c): RememberMe => new RememberMe(
        $c->get(Connection::class),
        $c->get(Clock::class),
        $c->get(Config::class)->int('auth.remember_days', 30),
    ));

    $c->factory(RegistrationService::class, static fn (Container $c): RegistrationService => new RegistrationService(
        $c->get(\App\Domain\User\UserRepository::class),
        $c->get(PasswordHasher::class),
        $c->get(\App\Domain\Auth\AuthTokens::class),
        $c->get(\App\Domain\Auth\AuthMailer::class),
        $c->get(\App\Kernel\Security\RateLimiter::class),
        $c->get(\App\Domain\Audit\AuditLog::class),
        $c->get(\App\Domain\Workspace\WorkspaceService::class),
        $c->get(LegalDocuments::class)->consentVersion(),
        $c->get(Clock::class),
    ));

    $c->factory(\App\Domain\Auth\Social\SocialAuthService::class, static fn (Container $c): \App\Domain\Auth\Social\SocialAuthService => new \App\Domain\Auth\Social\SocialAuthService(
        $c->get(\App\Domain\Auth\Social\IdentityRepository::class),
        $c->get(\App\Domain\User\UserRepository::class),
        $c->get(\App\Domain\Audit\AuditLog::class),
        $c->get(\App\Kernel\Security\RateLimiter::class),
        $c->get(Clock::class),
        $c->get(\App\Domain\Workspace\WorkspaceService::class),
        $c->get(LegalDocuments::class)->consentVersion(),
        $c->get(\App\Domain\Auth\RegistrationGate::class),
    ));

    $c->factory(LegalDocuments::class, static fn (Container $c): LegalDocuments => new LegalDocuments($base . '/resources/legal', $c->get(Connection::class)));

    $c->factory(HelpArticles::class, static fn (Container $c): HelpArticles => new HelpArticles($base . '/resources/help', $c->get(Connection::class)));

    $c->factory(\App\Kernel\HttpClient\EgressRoutes::class, static fn (Container $c): \App\Kernel\HttpClient\EgressRoutes => new \App\Kernel\HttpClient\EgressRoutes($c->get(Config::class)->env()));
    $c->factory(\App\Kernel\HttpClient\EgressHealth::class, static fn (Container $c): \App\Kernel\HttpClient\EgressHealth => new \App\Kernel\HttpClient\EgressHealth(static fn (): \Redis => $c->get(\Redis::class), $c->get(Config::class)->env(), $c->get(Clock::class)));
    $c->factory(GuzzleHttpClient::class, static fn (Container $c): GuzzleHttpClient => new GuzzleHttpClient($c->get(\App\Kernel\HttpClient\SsrfGuard::class), $c->get(LoggerInterface::class), null, $c->get(\App\Kernel\HttpClient\EgressRoutes::class), $c->get(\App\Kernel\HttpClient\EgressHealth::class)));
    $c->factory(HttpClientInterface::class, static fn (Container $c): HttpClientInterface => $c->get(GuzzleHttpClient::class));

    $c->factory(Translator::class, static fn (): Translator => new Translator($base . '/resources/lang', 'ru'));

    $c->factory(Settings::class, static fn (Container $c): Settings => new Settings($c->get(Connection::class), $c->get(Clock::class), $c->get(\Redis::class)));

    $c->factory(Permissions::class, static function (Container $c): Permissions {
        $matrix = [];
        foreach ($c->get(Config::class)->array('permissions') as $permission => $roles) {
            $matrix[(string) $permission] = is_array($roles) ? array_values(array_filter($roles, 'is_string')) : [];
        }

        return new Permissions($matrix);
    });

    $c->factory(\App\Domain\Admin\StaffAccess::class, static function (Container $c): \App\Domain\Admin\StaffAccess {
        $matrix = [];
        foreach ($c->get(Config::class)->array('admin_permissions') as $permission => $roles) {
            $matrix[(string) $permission] = is_array($roles) ? array_values(array_filter($roles, 'is_string')) : [];
        }

        return new \App\Domain\Admin\StaffAccess($c->get(Connection::class), $c->get(Clock::class), $matrix);
    });

    $c->factory(View::class, static function (Container $c) use ($base): View {
        $view = new View(
            $c->get(Config::class),
            $c->get(RequestContext::class),
            $c->get(Csrf::class),
            $c->get(Router::class),
            $c->get(Translator::class),
            $base . '/templates',
            $base . '/public',
            $base . '/storage/cache/twig',
        );
        // Workspace-aware helpers live above the Kernel, so they are attached here.
        $nav = $c->get(WorkspaceNav::class);
        $view->registerFunction('can', $nav->can(...));
        $view->registerFunction('current_workspace', $nav->current(...));
        $view->registerFunction('my_workspaces', $nav->mine(...));
        $view->registerFunction('workspace_nav', $nav->items(...));
        $view->registerFunction('plan_card', $nav->planCard(...));
        $view->registerFunction('platform_notices', $nav->platformNotices(...));
        $view->registerFunction('impersonating', $c->get(\App\Http\Auth\Impersonation::class)->target(...));
        $view->registerFunction('theme_version', $c->get(\App\Domain\Design\ThemeColors::class)->version(...));
        $view->registerFunction('site_text', $c->get(\App\Domain\Content\SiteContent::class)->text(...));
        $view->registerFunction('announcements', $c->get(\App\Http\AnnouncementFeed::class)->current(...));
        $view->registerFunction('admin_support_open', $c->get(\App\Domain\Support\Tickets::class)->openCount(...));
        $view->registerFunction('bytes', \App\Domain\Admin\SystemStatus::bytes(...));
        $adminNav = $c->get(\App\Http\Admin\AdminNav::class);
        $view->registerFunction('admin_can', $adminNav->can(...));
        $view->registerFunction('admin_role', $adminNav->role(...));
        $view->registerFunction('money', \App\Support\Money::format(...));

        return $view;
    });

    $c->factory(\App\Domain\Admin\SystemStatus::class, static fn (Container $c): \App\Domain\Admin\SystemStatus => new \App\Domain\Admin\SystemStatus(
        $c->get(Connection::class),
        $c->get(\Redis::class),
        $c->get(\App\Support\Heartbeat::class),
        $c->get(Clock::class),
        $c->get(Config::class),
        $base . '/storage/logs/app.log',
        $base . '/storage',
    ));
    $c->factory(MediaStorage::class, static fn (Container $c): MediaStorage => MediaStorageFactory::create($c->get(Config::class), $base));
    $c->factory(MediaLimits::class, static fn (Container $c): MediaLimits => MediaLimits::fromConfig($c->get(Config::class)));
    $c->factory(VideoProbe::class, static fn (): VideoProbe => new FfprobeVideoProbe());
    $c->factory(\App\Kernel\Security\MalwareScanner::class, static function (Container $c): \App\Kernel\Security\MalwareScanner {
        $env = $c->get(Config::class)->env();
        $driver = $env->string('MALWARE_SCANNER', 'fake');
        if ($driver === 'clamd') {
            return new \App\Kernel\Security\ClamdScanner($env->string('CLAMD_ENDPOINT', 'tcp://clamav:3310'));
        }
        if ($driver !== 'fake' || $env->string('APP_ENV') === 'production') {
            throw new \App\Kernel\Exception\ConfigException('Production requires the malware scanner.');
        }
        return new \App\Kernel\Security\FakeMalwareScanner();
    });

    // A library file that a planned post still needs cannot be deleted.
    $c->factory(MediaUsageChecker::class, static fn (Container $c): MediaUsageChecker => $c->get(PostUsageChecker::class));
    $c->factory(MediaService::class, static fn (Container $c): MediaService => new MediaService(
        $c->get(MediaRepository::class),
        $c->get(FolderRepository::class),
        $c->get(MediaStorage::class),
        $c->get(ImageProcessor::class),
        $c->get(VideoProbe::class),
        $c->get(MediaLimits::class),
        $c->get(MediaUsageChecker::class),
        $c->get(\App\Domain\Audit\AuditLog::class),
        $c->get(Clock::class),
        $c->get(Entitlements::class),
        $base . '/storage/tmp',
        $c->get(\App\Kernel\Security\MalwareScanner::class),
    ));

    // Social platforms: one adapter class per network. A new network = one more line in the list below (and its flag in PLATFORMS_ENABLED).
    $c->factory(TelegramClientFactory::class, static fn (Container $c): TelegramClientFactory => new TelegramClientFactory($c->get(HttpClientInterface::class)));
    $c->factory(MaxClientFactory::class, static fn (Container $c): MaxClientFactory => new MaxClientFactory(
        $c->get(HttpClientInterface::class),
        $c->get(Config::class)->string('platforms.max.api_base', 'https://platform-api2.max.ru'),
        (static function (string $file) use ($base): string {
            return $file === '' || str_starts_with($file, '/') ? $file : $base . '/' . $file;
        })($c->get(Config::class)->string('platforms.max.ca_file')),
    ));
    $c->factory(MaxAdapter::class, static fn (Container $c): MaxAdapter => new MaxAdapter(
        $c->get(MaxClientFactory::class),
        $c->get(\App\Integrations\Social\Max\MaxInspector::class),
        new MaxRateGate($c->get(\Redis::class)),
    ));
    $c->factory(VkApi::class, static fn (Container $c): VkApi => new VkApi(
        $c->get(HttpClientInterface::class),
        new VkRateGate($c->get(\Redis::class)),
        $c->get(Config::class)->string('platforms.vk.api_version', '5.199'),
    ));
    $c->factory(VkOAuth::class, static function (Container $c): VkOAuth {
        $config = $c->get(Config::class);

        return new VkOAuth($c->get(HttpClientInterface::class), $config->string('platforms.vk.client_id'), $config->string('platforms.vk.client_secret'), $config->string('platforms.vk.scope', 'wall photos video docs groups'));
    });
    $c->factory(VkAdapter::class, static fn (Container $c): VkAdapter => new VkAdapter(
        $c->get(VkApi::class),
        $c->get(VkCommunities::class),
        $c->get(Config::class)->int('platforms.vk.posts_per_day', 50),
    ));
    $c->factory(InstagramApi::class, static fn (Container $c): InstagramApi => new InstagramApi(
        $c->get(HttpClientInterface::class),
        $c->get(Config::class)->string('platforms.instagram.api_version', 'v23.0'),
    ));
    $c->factory(InstagramOAuth::class, static function (Container $c): InstagramOAuth {
        $config = $c->get(Config::class);

        return new InstagramOAuth($c->get(HttpClientInterface::class), $config->string('platforms.instagram.app_id'), $config->string('platforms.instagram.app_secret'), $config->string('platforms.instagram.scope'));
    });
    $c->factory(InstagramAdapter::class, static fn (Container $c): InstagramAdapter => new InstagramAdapter(
        $c->get(InstagramApi::class),
        $c->get(\App\Integrations\Social\Instagram\InstagramAccounts::class),
        $c->get(Config::class)->int('platforms.instagram.posts_per_day', 100),
    ));
    $c->factory(FakeAdapter::class, static fn (Container $c): FakeAdapter => new FakeAdapter($c->get(LoggerInterface::class)));
    $c->factory(PlatformRegistry::class, static function (Container $c): PlatformRegistry {
        $config = $c->get(Config::class);

        return new PlatformRegistry(
            [$c->get(TelegramAdapter::class), $c->get(VkAdapter::class), $c->get(MaxAdapter::class), $c->get(InstagramAdapter::class), $c->get(FakeAdapter::class)],
            array_values(array_filter(array_map('strval', $config->array('platforms.enabled')), static fn (string $v): bool => $v !== '')),
            !$config->isProduction(),
            // The owner can switch a platform off in the admin area (a kill switch for an outage or a lost key); read on every call.
            static fn (): array => array_values(array_filter((array) $c->get(Settings::class)->get('platforms.off', []), 'is_string')),
        );
    });
    $c->factory(ConnectCodes::class, static fn (Container $c): ConnectCodes => new ConnectCodes(
        $c->get(Connection::class),
        $c->get(Clock::class),
        $c->get(Config::class)->int('platforms.channels.connect_code_ttl', 900),
    ));

    // Billing. The price calculator needs the renewal window; every payment provider is built from its own settings.
    $c->factory(PriceCalculator::class, static fn (Container $c): PriceCalculator => new PriceCalculator($c->get(Config::class)->int('billing.renewal.lead_days', 3)));
    $c->factory(GatewayRegistry::class, static function (Container $c): GatewayRegistry {
        $factory = $c->get(GatewayFactory::class);

        return new GatewayRegistry(static fn (): array => $factory->all(), static fn (): array => $factory->enabled(), !$c->get(Config::class)->isProduction());
    });

    $c->factory(\App\Integrations\ContentProviders\ProviderHttp::class, static fn (Container $c): \App\Integrations\ContentProviders\ProviderHttp => new \App\Integrations\ContentProviders\ProviderHttp($c->get(HttpClientInterface::class), limits: $c->get(\App\Integrations\ContentProviders\ProviderLimits::class)));

    // Stage 17: links in posts. The guard needs the short host to refuse already shortened addresses; clicks are hashed with the app key.
    $c->factory(\App\Domain\Link\UrlGuard::class, static fn (Container $c): \App\Domain\Link\UrlGuard => new \App\Domain\Link\UrlGuard($c->get(\App\Domain\Settings\Settings::class), $c->get(Config::class)->string('links.short_domain')));
    $c->factory(\App\Domain\Link\ShortLinks::class, static fn (Container $c): \App\Domain\Link\ShortLinks => new \App\Domain\Link\ShortLinks(
        $c->get(Connection::class),
        $c->get(Clock::class),
        $c->get(\App\Domain\Link\UrlGuard::class),
        $c->get(Config::class)->string('app.url'),
        $c->get(Config::class)->string('links.short_domain'),
        $c->get(Crypto::class),
        $c->get(Config::class)->int('links.click_retention_days', 400),
    ));

    $c->factory(\App\Http\Controllers\Link\ShortLinkController::class, static fn (Container $c): \App\Http\Controllers\Link\ShortLinkController => new \App\Http\Controllers\Link\ShortLinkController(
        $c->get(\App\Domain\Link\ShortLinks::class),
        $c->get(\App\Domain\Link\UrlGuard::class),
        $c->get(View::class),
        $c->get(LoggerInterface::class),
        $c->get(Config::class)->string('links.short_domain'),
    ));
    $c->factory(\App\Http\Controllers\Link\LinkSettingsController::class, static fn (Container $c): \App\Http\Controllers\Link\LinkSettingsController => new \App\Http\Controllers\Link\LinkSettingsController(
        $c->get(View::class),
        $c->get(\App\Domain\Link\LinkSettings::class),
        $c->get(\App\Domain\Link\ShortLinks::class),
        $c->get(\App\Domain\Channel\ChannelRepository::class),
        $c->get(\App\Domain\Audit\AuditLog::class),
        $c->get(\App\Http\FormFlash::class),
        $c->get(Config::class)->int('links.manual_per_day', 100),
    ));
    $c->factory(\App\Integrations\Ai\OpenAiResponses::class, static fn (Container $c): \App\Integrations\Ai\OpenAiResponses => new \App\Integrations\Ai\OpenAiResponses($c->get(\App\Integrations\ContentProviders\ProviderHttp::class), $c->get(Config::class)->string('content_providers.openai_key'), $c->get(Config::class)->string('content_providers.openai_model')));
    // Stage 17: AI assistant models. The transport is shared; the factory allows the offline fake only outside production.
    $c->factory(\App\Integrations\Ai\Llm\LlmTransport::class, static fn (Container $c): \App\Integrations\Ai\Llm\LlmTransport => new \App\Integrations\Ai\Llm\LlmTransport($c->get(HttpClientInterface::class), $c->get(Config::class)->int('ai.timeout_seconds', 60)));
    $c->factory(\App\Integrations\Ai\Llm\LlmFactory::class, static fn (Container $c): \App\Integrations\Ai\Llm\LlmFactory => new \App\Integrations\Ai\Llm\LlmFactory($c->get(\App\Integrations\Ai\Llm\LlmSettings::class), $c->get(\App\Integrations\Ai\Llm\LlmTransport::class), !$c->get(Config::class)->isProduction() && $c->get(Config::class)->bool('ai.fake', true)));
    $c->factory(\App\Integrations\ContentProviders\ReplicateApi::class, static fn (Container $c): \App\Integrations\ContentProviders\ReplicateApi => new \App\Integrations\ContentProviders\ReplicateApi($c->get(\App\Integrations\ContentProviders\ProviderHttp::class), $c->get(Config::class)->string('content_providers.replicate_token')));
    $c->factory(\App\Integrations\Images\TinEyeImageSearchProvider::class, static fn (Container $c): \App\Integrations\Images\TinEyeImageSearchProvider => new \App\Integrations\Images\TinEyeImageSearchProvider($c->get(\App\Integrations\ContentProviders\ProviderHttp::class), $c->get(\App\Integrations\ContentProviders\SafeDownloads::class), $c->get(Config::class)->string('content_providers.tineye_key')));

    $c->factory(\App\Integrations\Ai\TextProvider::class, static fn (Container $c): \App\Integrations\Ai\TextProvider => $c->get(Config::class)->string('content_providers.text') === 'openai' ? $c->get(\App\Integrations\Ai\OpenAiTextProvider::class) : new \App\Integrations\Ai\FakeTextProvider(!$c->get(Config::class)->isProduction()));

    $c->factory(\App\Integrations\Selection\SemanticSelectionProvider::class, static fn (Container $c): \App\Integrations\Selection\SemanticSelectionProvider => $c->get(Config::class)->string('content_providers.semantic') === 'openai' ? $c->get(\App\Integrations\Selection\OpenAiSemanticSelectionProvider::class) : new \App\Integrations\Selection\FakeSemanticSelectionProvider(!$c->get(Config::class)->isProduction()));
    $c->factory(\App\Integrations\Discovery\DiscoveryProviders::class, static fn (Container $c): \App\Integrations\Discovery\DiscoveryProviders => new \App\Integrations\Discovery\DiscoveryProviders([
        new \App\Integrations\Discovery\FakeTelegramDiscoveryProvider(!$c->get(Config::class)->isProduction()),
        new \App\Integrations\Discovery\FakeWebNewsDiscoveryProvider(!$c->get(Config::class)->isProduction()),
        new \App\Integrations\Discovery\FakeSocialDiscoveryProvider(!$c->get(Config::class)->isProduction()),
    ]));

    $c->factory(\App\Integrations\Video\VideoProvider::class, static fn (Container $c): \App\Integrations\Video\VideoProvider => $c->get(Config::class)->string('content_providers.video') === 'replicate' ? $c->get(\App\Integrations\Video\ReplicateVideoProvider::class) : new \App\Integrations\Video\FakeVideoProvider(!$c->get(Config::class)->isProduction()));
    $c->factory(\App\Integrations\Images\ImageSearchProvider::class, static fn (Container $c): \App\Integrations\Images\ImageSearchProvider => $c->get(Config::class)->string('content_providers.image_search') === 'tineye' ? $c->get(\App\Integrations\Images\TinEyeImageSearchProvider::class) : new \App\Integrations\Images\FakeImageSearchProvider());
    $c->factory(\App\Integrations\Images\ImageEnhancementProvider::class, static fn (Container $c): \App\Integrations\Images\ImageEnhancementProvider => match ($c->get(Config::class)->string('content_providers.image_enhancement')) {
        'replicate' => $c->get(\App\Integrations\Images\ReplicateImageEnhancementProvider::class), 'disabled' => new \App\Integrations\Images\DisabledImageEnhancementProvider(), default => new \App\Integrations\Images\FakeImageEnhancementProvider(!$c->get(Config::class)->isProduction())
    });

    $c->factory(\App\Kernel\ReadinessState::class, static function (Container $c) use ($base): \App\Kernel\ReadinessState {
        $config = $c->get(Config::class);
        $expected = count(\App\Support\Fs::glob($base . '/database/migrations/*.php'));
        $identity = hash('sha256', json_encode([[$config->string('database.host'), $config->string('database.database')], $config->env()->string('VAULT_TRANSIT_URL'), $config->env()->string('VAULT_TRANSIT_KEY'), $config->env()->string('CLAMD_ENDPOINT'), $expected], JSON_THROW_ON_ERROR));
        return new \App\Kernel\ReadinessState(
            static fn (): \Redis => $c->get(\Redis::class),
            $c->get(Clock::class),
            static fn (): array => $c->get(HealthCheck::class)->ready($expected),
            $identity
        );
    });
    $c->factory(HealthCheck::class, static fn (Container $c): HealthCheck => new HealthCheck($c->get(Config::class)->env()->all(), $c->get(\App\Kernel\HttpClient\EgressHealth::class), $c->get(Config::class)->env()->string('CRYPTO_BACKEND') === 'vault' ? $c->get(\App\Kernel\Security\IsolatedCrypto::class) : null));

    $c->factory(Schedule::class, static function (Container $c) use ($base): Schedule {
        $schedule = new Schedule($c, $c->get(LoggerInterface::class));
        $define = require $base . '/config/schedule.php';
        $define($schedule);

        return $schedule;
    });

    $c->factory(SeedCommand::class, static fn (Container $c): SeedCommand => new SeedCommand(
        $c->get(Connection::class),
        $c->get(Config::class),
        $base . '/database/seeds',
    ));
};
