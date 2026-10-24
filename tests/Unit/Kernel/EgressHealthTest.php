<?php

declare(strict_types=1);

namespace App\Tests\Unit\Kernel;

use App\Kernel\Env;
use App\Kernel\HttpClient\EgressHealth;
use App\Tests\Support\FakeClock;
use PHPUnit\Framework\TestCase;

/** Required egress is fail-closed on stale observations/config rotation, without real network activity. */
final class EgressHealthTest extends TestCase
{
    public function testLastSuccessDoesNotSurviveFailureStalenessOrProxyCredentialRotation(): void
    {
        $data = [];
        $redis = $this->createMock(\Redis::class);
        $redis->method('hMset')->willReturnCallback(static function (string $key, array $fields) use (&$data): bool {
            $data[$key] = array_merge($data[$key] ?? [], $fields);
            return true;
        });
        // Use a reference capture so observations written after construction are visible.
        $redis->method('hGetAll')->willReturnCallback(static function (string $key) use (&$data): array {
            return $data[$key] ?? [];
        });
        $redis->method('expire')->willReturn(true);
        $clock = new FakeClock();
        $values = ['APP_KEY' => 'synthetic-fixture-key', 'EGRESS_REQUIRED_PROVIDERS' => 'telegram',
            'EGRESS_TELEGRAM_MODE' => 'tunnel', 'EGRESS_TELEGRAM_PROXY_URL' => 'socks5h://fixture:secret-one@127.0.0.1:1080'];
        $health = new EgressHealth($redis, new Env($values), $clock);
        self::assertFalse($health->requiredReady());
        $health->record('telegram', 'tunnel', true);
        self::assertTrue($health->requiredReady());
        $health->record('telegram', 'tunnel', false);
        self::assertFalse($health->requiredReady());
        $health->record('telegram', 'tunnel', true);
        $clock->advance(301);
        self::assertFalse($health->requiredReady());
        $health->record('telegram', 'tunnel', true);
        $values['EGRESS_TELEGRAM_PROXY_URL'] = 'socks5h://fixture:secret-two@127.0.0.1:1080';
        $rotated = new EgressHealth($redis, new Env($values), $clock);
        self::assertFalse($rotated->requiredReady());
        self::assertNull($rotated->report()['telegram']['last_success']);
        self::assertStringNotContainsString('secret-one', json_encode($health->report(), JSON_THROW_ON_ERROR));
    }

    public function testReaderObservationMustMatchTheLoadedSecretRoute(): void
    {
        $data = [];
        $redis = $this->createMock(\Redis::class);
        $redis->method('hMset')->willReturnCallback(static function (string $key, array $fields) use (&$data): bool {
            $data[$key] = $fields;
            return true;
        });
        $redis->method('hGetAll')->willReturnCallback(static function (string $key) use (&$data): array {
            return $data[$key] ?? [];
        });
        $redis->method('expire')->willReturn(true);
        $env = new Env(['SOURCES_READER_SECRET' => str_repeat('s', 32), 'EGRESS_REQUIRED_PROVIDERS' => 'telegram-reader']);
        $health = new EgressHealth($redis, $env, new FakeClock());
        $id = (new \App\Kernel\HttpClient\EgressRoutes($env))->readerIdentity(str_repeat('s', 32));
        $health->recordReader('unknown', 'direct', $id);
        self::assertSame('unknown', $health->report()['telegram-reader']['state']);
        $health->recordReader('ok', 'direct', $id);
        self::assertTrue($health->requiredReady());
        $health->recordReader('ok', 'direct', str_repeat('0', 64));
        self::assertFalse($health->requiredReady());
    }

    public function testTelemetryFailureDoesNotTurnACompletedRequestIntoARetry(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('hMset')->willThrowException(new \RuntimeException('unavailable'));
        $redis->method('hGetAll')->willThrowException(new \RuntimeException('unavailable'));
        $health = new EgressHealth($redis, new Env(['EGRESS_REQUIRED_PROVIDERS' => 'telegram']), new FakeClock());
        $health->record('telegram', 'direct', true);
        self::assertFalse($health->requiredReady());
        self::assertSame('unknown', $health->report()['telegram']['state']);
    }
}
