<?php

declare(strict_types=1);

namespace App\Kernel;

use PDO;
use Redis;
use Throwable;

/**
 * Liveness probe for `/healthz`: checks that MySQL and Redis answer.
 *
 * Temporary stage-00 implementation; the real kernel (stage 01) wires it through the container.
 * Never exposes error details: only "ok" or "fail" per dependency.
 */
final class HealthCheck
{
    /**
     * @param array<string, string> $env environment variables (DB_*, REDIS_*)
     */
    public function __construct(private readonly array $env)
    {
    }

    /**
     * @return array{db: string, redis: string}
     */
    public function run(): array
    {
        return ['db' => $this->checkDb(), 'redis' => $this->checkRedis()];
    }

    /**
     * @param array{db: string, redis: string} $result
     */
    public static function isHealthy(array $result): bool
    {
        return $result['db'] === 'ok' && $result['redis'] === 'ok';
    }

    private function checkDb(): string
    {
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $this->env['DB_HOST'] ?? 'mysql',
                $this->env['DB_PORT'] ?? '3306',
                $this->env['DB_DATABASE'] ?? 'app',
            );
            $pdo = new PDO($dsn, $this->env['DB_USERNAME'] ?? 'app', $this->env['DB_PASSWORD'] ?? '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 2,
            ]);
            $pdo->query('SELECT 1');

            return 'ok';
        } catch (Throwable) {
            return 'fail';
        }
    }

    private function checkRedis(): string
    {
        try {
            $redis = new Redis();
            $redis->connect($this->env['REDIS_HOST'] ?? 'redis', (int) ($this->env['REDIS_PORT'] ?? 6379), 2.0);
            $redis->ping();

            return 'ok';
        } catch (Throwable) {
            return 'fail';
        }
    }
}
