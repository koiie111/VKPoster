<?php

declare(strict_types=1);

namespace App\Tests\Integration\Kernel;

use App\Kernel\Operations\OperationalMonitor;
use App\Kernel\HttpClient\HttpClientInterface;
use App\Tests\Support\MockHttpClient;
use App\Tests\Support\TestEnv;
use PHPUnit\Framework\TestCase;

/** Alert dispatch is opted in and Redis claims suppress repeated identical incidents. */
final class OperationalMonitorTest extends TestCase
{
    public function testOptInDeduplicationAndSafePayload(): void
    {
        $redis = TestEnv::redis();
        $redis->flushDB();
        $http = new MockHttpClient();
        $app = TestEnv::app(['OPS_MONITOR_ENABLED' => '1', 'OPS_TELEGRAM_BOT_TOKEN' => 'synthetic', 'OPS_TELEGRAM_CHAT_ID' => '1']);
        $app->container()->instance(HttpClientInterface::class, $http);
        $monitor = $app->container()->get(OperationalMonitor::class);
        $monitor->tick();
        $count = count($http->requests);
        self::assertGreaterThan(0, $count);
        $monitor->tick();
        self::assertCount($count, $http->requests);
        foreach ($http->requests as $request) {
            $body = json_encode($request['options']['json'], JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('synthetic', $body);
            self::assertSame(3, $request['options']['timeout']);
        }
        $disabled = TestEnv::app();
        $disabled->container()->instance(HttpClientInterface::class, $http);
        $disabled->container()->get(OperationalMonitor::class)->tick();
        self::assertCount($count, $http->requests);
        $redis->flushDB();
    }
}
