<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Kernel\HealthCheck;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Smoke test: the app can reach MySQL and Redis from the test environment.
 */
#[CoversClass(HealthCheck::class)]
final class HealthTest extends TestCase
{
    public function testHealthzReportsDbAndRedisOk(): void
    {
        $env = [];
        foreach (['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'REDIS_HOST', 'REDIS_PORT'] as $key) {
            $value = getenv($key);
            if ($value !== false) {
                $env[$key] = $value;
            }
        }

        $result = (new HealthCheck($env))->run();

        self::assertSame(['db' => 'ok', 'redis' => 'ok'], $result);
        self::assertTrue(HealthCheck::isHealthy($result));
    }

    public function testUnreachableDependenciesAreReportedAsFail(): void
    {
        $result = (new HealthCheck(['DB_HOST' => '127.0.0.1', 'DB_PORT' => '1', 'REDIS_HOST' => '127.0.0.1', 'REDIS_PORT' => '1']))->run();

        self::assertSame(['db' => 'fail', 'redis' => 'fail'], $result);
        self::assertFalse(HealthCheck::isHealthy($result));
    }
    public function testLivenessDoesNotNeedRedisOrDatabase(): void
    {
        $app = \App\Tests\Support\TestEnv::app(['DB_HOST' => '127.0.0.1', 'DB_PORT' => '1', 'REDIS_HOST' => '127.0.0.1', 'REDIS_PORT' => '1']);
        $response = $app->handle(\App\Kernel\Http\Request::create('GET', '/healthz'));
        self::assertSame(200, $response->status);
        self::assertStringContainsString('ok', $response->body);
        $ready = $app->handle(\App\Kernel\Http\Request::create('GET', '/readyz'));
        self::assertSame(503, $ready->status);
        self::assertStringNotContainsString('SQLSTATE', $ready->body);
    }

    public function testReadinessFailsWhenPrivateScannerIsUnavailable(): void
    {
        $health = new HealthCheck(\App\Tests\Support\TestEnv::env(['MALWARE_SCANNER' => 'clamd', 'CLAMD_ENDPOINT' => 'unix:///missing-stage19-scanner.sock'])->all());
        self::assertSame('fail', $health->ready(46)['malware_scanner']);
    }

    public function testReadinessChecksSchemaAndDependencies(): void
    {
        $health = new HealthCheck(\App\Tests\Support\TestEnv::env()->all());
        $expected = count(\App\Support\Fs::glob(dirname(__DIR__, 2) . '/database/migrations/*.php'));
        self::assertSame(['db' => 'ok', 'redis' => 'ok', 'schema' => 'ok'], $health->ready($expected));
        self::assertSame('fail', $health->ready($expected + 1)['schema']);
    }

}
