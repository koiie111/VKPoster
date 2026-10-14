<?php

declare(strict_types=1);

namespace App\Tests\Unit\Approval;

use App\Kernel\Security\RateLimiter;
use App\Support\SystemClock;
use PHPUnit\Framework\TestCase;

final class PinRateLimiterTest extends TestCase
{
    public function testDisconnectedClientCannotImplicitlyConnectForPinProtection(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('isConnected')->willReturn(false);
        $redis->expects(self::never())->method('eval');
        $this->expectException(\RuntimeException::class);
        (new RateLimiter($redis, new SystemClock()))->attempt('guest-pin:test', 5, 900, failClosed: true);
    }

    public function testFailOpenCheckDoesNotConnectClientBeforePinCheck(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('isConnected')->willReturn(false);
        $redis->expects(self::never())->method('eval');
        $limiter = new RateLimiter($redis, new SystemClock());
        self::assertTrue($limiter->attempt('public:test', 5, 900)->allowed);
        $this->expectException(\RuntimeException::class);
        $limiter->attempt('guest-pin:test', 5, 900, failClosed: true);
    }

    public function testFailedRedisCommandIsUnavailableRatherThanRateLimited(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('isConnected')->willReturn(true);
        $redis->method('eval')->willReturn(false);
        $this->expectException(\RuntimeException::class);
        (new RateLimiter($redis, new SystemClock()))->attempt('guest-pin:test', 5, 900, failClosed: true);
    }
}
