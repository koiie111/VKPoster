<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Domain\Api\ApiTokens;
use App\Kernel\Http\Router;
use PHPUnit\Framework\TestCase;

/** Bidirectional route/spec parity, declared scopes, idempotency and error/header contracts. */
final class OpenApiTest extends TestCase
{
    public function testEveryPublicRouteMatchesTheOpenApiDescription(): void
    {
        $base = dirname(__DIR__, 3);
        $spec = json_decode((string) file_get_contents($base . '/public/api/openapi.yaml'), true, 64, JSON_THROW_ON_ERROR);
        self::assertSame('3.1.0', $spec['openapi']);
        $router = new Router();
        (require $base . '/config/routes.php')($router);
        $actual = [];
        foreach ($router->routes() as $route) {
            if (!str_starts_with($route->pattern, '/api/v1/')) {
                continue;
            }
            self::assertFalse($route->requiresCsrf());
            foreach ($route->methods as $method) {
                $actual[] = strtolower($method) . ' ' . $route->pattern;
            }
        }
        $declared = [];
        foreach ($spec['paths'] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                $declared[] = $method . ' ' . $path;
                self::assertContains($operation['x-required-scope'], ApiTokens::SCOPES);
                self::assertSame([['bearerAuth' => []]], $operation['security']);
                self::assertArrayHasKey('401', $operation['responses']);
                self::assertArrayHasKey('429', $operation['responses']);
                if ($method !== 'get') {
                    self::assertContains('Idempotency-Key', array_column($operation['parameters'], 'name'));
                }
            }
        }
        sort($actual);
        sort($declared);
        self::assertSame($actual, $declared);
        self::assertCount(11, $actual);
        self::assertSame(['integer', 'null'], $spec['components']['schemas']['Statistics']['properties']['totals']['properties']['reach']['type']);
        self::assertArrayHasKey('X-RateLimit-Reset', $spec['components']['responses']['Error']['headers']);
    }
}
