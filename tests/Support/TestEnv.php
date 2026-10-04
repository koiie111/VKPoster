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
            'LOG_DISABLE_STDERR' => '1',
            'ARGON_MEMORY_KIB' => '1024',
            'ARGON_TIME_COST' => '1',
        ];

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
        return Application::create(self::basePath(), self::env($overrides));
    }

    /**
     * A fresh connection to the test database (`app_test`).
     */
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
