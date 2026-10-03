<?php

declare(strict_types=1);

namespace App\Tests\Unit\Kernel;

use App\Kernel\Config;
use App\Kernel\Env;
use App\Kernel\Exception\ConfigException;
use App\Tests\Support\TestEnv;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Config loading, typed getters and production safety checks.
 */
#[CoversClass(Config::class)]
#[CoversClass(Env::class)]
final class ConfigTest extends TestCase
{
    public function testLoadsFilesAndReadsDottedKeys(): void
    {
        $config = TestEnv::config(['APP_NAME' => 'demo', 'APP_DEBUG' => '1', 'TRUSTED_PROXIES' => '10.0.0.0/8, 192.168.0.1']);

        self::assertSame('demo', $config->string('app.name'));
        self::assertTrue($config->bool('app.debug'));
        self::assertSame(['10.0.0.0/8', '192.168.0.1'], $config->array('app.trusted_proxies'));
        self::assertSame(6379, $config->int('database.redis.port'));
        self::assertSame('fallback', $config->string('app.missing', 'fallback'));
        self::assertFalse($config->isProduction());
    }

    public function testMissingRequiredVariableFailsWithoutLeakingValues(): void
    {
        $vars = TestEnv::env()->all();
        unset($vars['APP_KEY']);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('APP_KEY');
        Config::load(TestEnv::basePath() . '/config', new Env($vars));
    }

    public function testProductionForbidsDebug(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('APP_DEBUG');
        TestEnv::config(['APP_ENV' => 'production', 'APP_DEBUG' => '1']);
    }

    public function testProductionForbidsDevFlags(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('DEV_LOGIN');
        TestEnv::config(['APP_ENV' => 'production', 'DEV_LOGIN' => 'true']);
    }

    public function testProductionAllowsDisabledDevFlags(): void
    {
        $config = TestEnv::config(['APP_ENV' => 'production', 'DEV_LOGIN' => '0']);

        self::assertTrue($config->isProduction());
    }

    public function testUnknownEnvironmentIsRejected(): void
    {
        $this->expectException(ConfigException::class);
        TestEnv::config(['APP_ENV' => 'staging']);
    }

    public function testEnvTypedGetters(): void
    {
        $env = new Env(['A' => '12', 'B' => 'yes', 'C' => 'abc', 'D' => '', 'E' => 'x, ,y']);

        self::assertSame(12, $env->int('A'));
        self::assertTrue($env->bool('B'));
        self::assertSame(5, $env->int('D', 5));
        self::assertSame(['x', 'y'], $env->list('E'));
        self::assertSame([], $env->list('NOPE'));
        self::assertFalse($env->has('D'));
        $this->expectException(ConfigException::class);
        $env->int('C');
    }
}
