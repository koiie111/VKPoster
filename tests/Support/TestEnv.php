<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Kernel\Application;
use App\Kernel\Config;
use App\Kernel\Database\Connection;
use App\Kernel\Env;

/**
 * Builds the environment and application objects used by tests (never reads the developer's `.env`).
 */
final class TestEnv
{
    public const TELEGRAM_TOKEN = '123456789:TEST-token-not-real';

    /** The shared bot of the channels stage (not a real token); its id is the part before the colon. */
    public const SHARED_BOT_TOKEN = '987654321:SHARED-bot-token-not-real-0123456789';

    public const WEBHOOK_SECRET = 'test-webhook-secret-0123456789';

    /** The shared MAX bot of the MAX stage (not a real token) and its webhook secret. */
    public const MAX_BOT_TOKEN = 'max-shared-bot-token-not-real-0123456789';

    public const MAX_WEBHOOK_SECRET = 'max-webhook-secret-0123456789';

    public const KEY = 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=';

    private static function process(string $name, string $default): string
    {
        $value = getenv($name);

        return $value === false || $value === '' ? $default : $value;
    }

    public static function basePath(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @param array<string, string> $overrides
     */
    public static function env(array $overrides = []): Env
    {
        $vars = [
            'APP_ENV' => 'testing',
            'APP_URL' => 'http://localhost',
            'APP_KEY' => self::KEY,
            'DB_HOST' => self::process('DB_HOST', 'mysql'),
            'DB_PORT' => self::process('DB_PORT', '3306'),
            'DB_DATABASE' => 'app_test',
            'DB_USERNAME' => self::process('DB_USERNAME', 'app'),
            'DB_PASSWORD' => self::process('DB_PASSWORD', 'app'),
            'REDIS_HOST' => self::process('REDIS_HOST', 'redis'),
            'REDIS_PORT' => self::process('REDIS_PORT', '6379'),
            'REDIS_DB' => '15', // dev data (sessions, rate limits) lives in db 0 and must survive test runs
            'BACKUP_DB_SOCKET' => self::process('TEST_DB_SOCKET', ''),
            'RESTORE_DB_SOCKET' => self::process('TEST_DB_SOCKET', ''),
            'LOG_DISABLE_STDERR' => '1',
            // Sources are off by default in production; the tests exercise the feature.
            'SOURCES_ENABLED' => '1',
            'ANALYTICS_COLLECTION_ENABLED' => '1',
            'ARGON_MEMORY_KIB' => '1024',
            'ARGON_TIME_COST' => '1',
            // Social sign-in: the fake provider and a test Telegram bot (the token is not a real one).
            'DEV_OAUTH_FAKE' => '1',
            'TELEGRAM_LOGIN_BOT_TOKEN' => self::TELEGRAM_TOKEN,
            'TELEGRAM_LOGIN_BOT_NAME' => 'ezposter_test_bot',
            // Channels: Telegram plus the test network, the shared bot, and a webhook secret.
            'PLATFORMS_ENABLED' => 'telegram,vk,max,instagram,fake',
            'VK_CLIENT_ID' => 'vk-test-app',
            'VK_CLIENT_SECRET' => 'vk-test-secret',
            'INSTAGRAM_APP_ID' => 'ig-test-app',
            'INSTAGRAM_APP_SECRET' => 'ig-test-secret',
            'TELEGRAM_BOT_TOKEN' => self::SHARED_BOT_TOKEN,
            'TELEGRAM_BOT_USERNAME' => 'ezposter_bot',
            'TELEGRAM_WEBHOOK_SECRET' => self::WEBHOOK_SECRET,
            'MAX_BOT_TOKEN' => self::MAX_BOT_TOKEN,
            'MAX_BOT_USERNAME' => 'ezposter_max_bot',
            'MAX_WEBHOOK_SECRET' => self::MAX_WEBHOOK_SECRET,
            // Billing: both providers configured with made-up credentials (the HTTP client is a mock), the test provider is always available.
            'YOOKASSA_SHOP_ID' => '100500',
            'YOOKASSA_SECRET_KEY' => 'test_yookassa_secret_not_real',
            'TBANK_TERMINAL_KEY' => BillingFixtures::TBANK_TERMINAL,
            'TBANK_PASSWORD' => BillingFixtures::TBANK_PASSWORD,
            'BILLING_TAX_SYSTEM' => 'usn_income',
            'BILLING_VAT' => 'none',
            'BILLING_SELLER_NAME' => 'ИП Тестов Т. Т.',
            'BILLING_SELLER_INN' => '500100732259',
        ];

        // The fake provider is refused in production, so tests that boot a production app get it switched off.
        if (($overrides['APP_ENV'] ?? '') === 'production') {
            $vars['DEV_OAUTH_FAKE'] = '0';
            $vars['MALWARE_SCANNER'] = 'clamd';
            $vars['APP_URL'] = 'https://example.com';
            $vars['TRUSTED_PROXIES'] = '127.0.0.1';
            $vars['REDIS_PASSWORD'] = str_repeat('r', 48);
            $vars['REDIS_HOST'] = self::process('REDIS_PRODUCTION_TEST_HOST', 'redis-production-test');
            $vars['REDIS_PORT'] = self::process('REDIS_PRODUCTION_TEST_PORT', '6379');
        }

        if (($overrides['APP_ENV'] ?? '') === 'production') {
            unset($vars['APP_KEY']);
            $vars['CRYPTO_BACKEND'] = 'vault';
            $vars['VAULT_TRANSIT_URL'] = 'https://synthetic-vault.invalid';
            $vars['VAULT_TOKEN_FILE'] = self::tokenFixture();
            $vars['EGRESS_IDENTITY_SALT'] = str_repeat('s', 32);
        }
        return new Env($overrides + $vars);
    }

    /**
     * @param array<string, string> $overrides
     */
    public static function config(array $overrides = []): Config
    {
        return Config::load(self::basePath() . '/config', self::env($overrides));
    }

    /**
     * @param array<string, string> $overrides
     */
    public static function app(array $overrides = []): Application
    {
        $app = Application::create(self::basePath(), self::env($overrides));
        if (($overrides['APP_ENV'] ?? '') === 'production') {
            $app->container()->instance(\App\Kernel\Security\IsolatedCrypto::class, new FakeIsolatedCrypto());
        }
        return $app;
    }

    /** Private runtime-only synthetic capability; never reads an owner file. */
    private static function tokenFixture(): string
    {
        static $path = null;
        if ($path === null) {
            $path = tempnam(sys_get_temp_dir(), 'transit-fixture-');
            if ($path === false) {
                throw new \RuntimeException('Cannot provision test capability.');
            }
            file_put_contents($path, bin2hex(random_bytes(32)));
            chmod($path, 0600);
            $file = $path;
            register_shutdown_function(static function () use ($file): void {
                if (is_file($file)) {
                    unlink($file);
                }
            });
        }
        return $path;
    }

    /** A fresh connection to the test database (`app_test`). */
    public static function connection(): Connection
    {
        $config = self::config();

        return new Connection(
            $config->string('database.host'),
            $config->int('database.port'),
            $config->string('database.database'),
            $config->string('database.username'),
            $config->string('database.password'),
        );
    }

    public static function redis(): \Redis
    {
        $config = self::config();
        $redis = new \Redis();
        $redis->connect($config->string('database.redis.host'), $config->int('database.redis.port'), 2.0);
        $redis->select($config->int('database.redis.db'));

        return $redis;
    }
}
