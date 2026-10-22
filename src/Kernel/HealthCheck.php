<?php

declare(strict_types=1);

namespace App\Kernel;

use PDO;
use Redis;
use Throwable;

/**
 * Liveness probe for `/healthz`: checks that MySQL and Redis answer.
 *
 * Opens its own short-timeout connections (not the pooled `Connection`) so a hung dependency cannot stall the probe.
 * Never exposes error details: only "ok" or "fail" per dependency.
 */
final class HealthCheck
{
    /**
     * @param array<string, string> $env environment variables (DB_*, REDIS_*)
     */
    public function __construct(private readonly array $env, private readonly ?\App\Kernel\HttpClient\EgressHealth $egress = null, private readonly ?\App\Kernel\Security\IsolatedCrypto $crypto = null)
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

    /** Readiness includes applied schema; no session, scans or external providers are needed. */
    /** @return array<string, string> */
    public function ready(int $expectedMigrations): array
    {
        $result = $this->run();
        if (($this->env['CRYPTO_BACKEND'] ?? '') === 'vault') {
            $result['crypto'] = 'fail';
            try {
                if ($this->crypto !== null) {
                    foreach (['application', 'webhook', 'signer', 'pdf', 'password', 'clicks'] as $purpose) {
                        $this->crypto->version($purpose, true);
                    }
                    $result['crypto'] = 'ok';
                }
            } catch (Throwable) {
            }
        }
        if (($this->env['MALWARE_SCANNER'] ?? '') === 'clamd') {
            try {
                (new \App\Kernel\Security\ClamdScanner($this->env['CLAMD_ENDPOINT'] ?? 'tcp://clamav:3310', 1, 2))->scanBytes('');
                $result['malware_scanner'] = 'ok';
            } catch (Throwable) {
                $result['malware_scanner'] = 'fail';
            }
        }
        if (($this->env['EGRESS_REQUIRED_PROVIDERS'] ?? '') !== '') {
            $result['egress'] = $this->egress?->requiredReady() === true ? 'ok' : 'fail';
        }
        $result['schema'] = 'fail';
        if ($result['db'] === 'ok') {
            try {
                $pdo = $this->connectDb();
                $statement = $pdo->query('SELECT COUNT(*) FROM migrations');
                $count = $statement === false ? -1 : (int) $statement->fetchColumn();
                $result['schema'] = $count === $expectedMigrations ? 'ok' : 'fail';
            } catch (Throwable) {
            }
        }

        return $result;
    }

    private function connectDb(): PDO
    {
        return new PDO(
            sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $this->env['DB_HOST'] ?? 'mysql',
                $this->env['DB_PORT'] ?? '3306',
                $this->env['DB_DATABASE'] ?? 'app'
            ),
            $this->env['DB_USERNAME'] ?? 'app',
            $this->env['DB_PASSWORD'] ?? '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 2]
        );
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
            if (($this->env['REDIS_PASSWORD'] ?? '') !== '') {
                $redis->auth($this->env['REDIS_PASSWORD']);
            }
            $redis->ping();

            return 'ok';
        } catch (Throwable) {
            return 'fail';
        }
    }
}
