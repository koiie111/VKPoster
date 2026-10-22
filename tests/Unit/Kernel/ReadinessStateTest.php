<?php

declare(strict_types=1);

namespace App\Tests\Unit\Kernel;

use App\Http\Controllers\HealthController;
use App\Kernel\ReadinessState;
use App\Tests\Support\FakeClock;
use App\Tests\Support\TestEnv;
use PHPUnit\Framework\TestCase;

/** Public requests read cached state; only one background owner may run expensive probes. */
final class ReadinessStateTest extends TestCase
{
    public function testOneHundredConcurrentRequestsNeverProbeAndBackgroundIsSingleFlight(): void
    {
        $clock = new FakeClock();
        $identity = 'test-' . bin2hex(random_bytes(12));
        $counter = $identity . ':probes';
        $redis = TestEnv::redis();
        $state = new ReadinessState(static fn () => TestEnv::redis(), $clock, static function () use ($counter): array {
            TestEnv::redis()->incr($counter);
            usleep(100000);
            return ['transit' => 'ok', 'antivirus' => 'ok'];
        }, $identity);
        try {
            $children = [];
            for ($i = 0; $i < 100; ++$i) {
                $pid = pcntl_fork();
                self::assertNotSame(-1, $pid);
                if ($pid === 0) {
                    // Simultaneous monitor ticks also cannot cause a probe stampede.
                    $state->refresh();
                    $app = TestEnv::app();
                    $app->container()->instance(ReadinessState::class, $state);
                    $response = $app->handle(\App\Kernel\Http\Request::create('GET', '/readyz'));
                    $live = $app->handle(\App\Kernel\Http\Request::create('GET', '/healthz'));
                    exit(in_array($response->status, [200, 503], true) && $live->status === 200 ? 0 : 1);
                }
                $children[] = $pid;
            }
            foreach ($children as $child) {
                pcntl_waitpid($child, $status);
                self::assertSame(0, pcntl_wexitstatus($status));
            }
            self::assertSame('1', $redis->get($counter));
            $controller = new HealthController($state);
            for ($i = 0; $i < 100; ++$i) {
                self::assertSame(200, $controller->ready()->status);
                self::assertSame('{"status":"ok"}', $controller->ready()->body);
            }
            self::assertSame('1', $redis->get($counter));
            $clock->advance(91);
            self::assertSame(503, $controller->ready()->status, 'Expired successes fail closed.');
            self::assertSame('{"status":"unavailable"}', $controller->ready()->body);
            self::assertSame(200, $controller->show()->status);
            $down = new ReadinessState(static fn () => TestEnv::redis(), $clock, static fn () => ['transit' => 'fail'], $identity);
            $down->refresh();
            self::assertFalse($state->ready());
            self::assertSame('1', $redis->get($counter), 'Neither public endpoint performs dependency probes.');
        } finally {
            $redis->del($counter, 'readiness:' . $identity, 'readiness:' . $identity . ':lock');
        }
    }

    public function testUnavailableCacheAndProbeExceptionFailClosedWithoutDetails(): void
    {
        $state = new ReadinessState(static function (): \Redis {
            throw new \RuntimeException('internal endpoint');
        }, new FakeClock(), static fn () => ['crypto' => 'ok'], 'unavailable');
        self::assertSame('{"status":"unavailable"}', (new HealthController($state))->ready()->body);
    }
}
