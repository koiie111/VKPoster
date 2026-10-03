<?php

declare(strict_types=1);

namespace App\Tests\Integration\Kernel;

use App\Kernel\Security\RateLimiter;
use App\Kernel\Session\RedisSessionStore;
use App\Tests\Support\FakeClock;
use App\Tests\Support\TestEnv;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Redis;

/**
 * Redis-backed pieces: sliding-window rate limiter and session store.
 */
#[CoversClass(RateLimiter::class)]
#[CoversClass(RedisSessionStore::class)]
final class RedisTest extends TestCase
{
    private Redis $redis;
    private string $prefix;

    protected function setUp(): void
    {
        $this->redis = TestEnv::redis();
        $this->prefix = 'test:' . bin2hex(random_bytes(4)) . ':';
    }

    protected function tearDown(): void
    {
        $keys = $this->redis->keys($this->prefix . '*');
        if (is_array($keys)) {
            foreach ($keys as $key) {
                $this->redis->del($key);
            }
        }
    }

    public function testRateLimiterAllowsUpToMaxThenBlocks(): void
    {
        $clock = new FakeClock();
        $limiter = new RateLimiter($this->redis, $clock, $this->prefix);

        $results = [];
        for ($i = 0; $i < 4; ++$i) {
            $results[] = $limiter->attempt('login:1.2.3.4', 3, 60);
        }

        self::assertSame([true, true, true, false], array_map(static fn ($r): bool => $r->allowed, $results));
        self::assertSame([2, 1, 0, 0], array_map(static fn ($r): int => $r->remaining, $results));
        self::assertSame(60, $results[3]->retryAfter);
    }

    public function testRateLimiterWindowSlides(): void
    {
        $clock = new FakeClock();
        $limiter = new RateLimiter($this->redis, $clock, $this->prefix);
        $limiter->attempt('k', 2, 60);
        $clock->advance(30);
        $limiter->attempt('k', 2, 60);
        self::assertFalse($limiter->attempt('k', 2, 60)->allowed);

        $clock->advance(31); // the first hit (t=0) has left the window, the second (t=30) has not
        $result = $limiter->attempt('k', 2, 60);

        self::assertTrue($result->allowed);
        self::assertSame(0, $result->remaining);
        $blocked = $limiter->attempt('k', 2, 60);
        self::assertFalse($blocked->allowed);
        self::assertSame(29, $blocked->retryAfter);
    }

    public function testRateLimiterBucketsAreIndependentAndClearable(): void
    {
        $limiter = new RateLimiter($this->redis, new FakeClock(), $this->prefix);
        self::assertTrue($limiter->attempt('a', 1, 60)->allowed);
        self::assertFalse($limiter->attempt('a', 1, 60)->allowed);
        self::assertTrue($limiter->attempt('b', 1, 60)->allowed);

        $limiter->clear('a');

        self::assertTrue($limiter->attempt('a', 1, 60)->allowed);
    }

    public function testBlockedAttemptsDoNotExtendTheBan(): void
    {
        $clock = new FakeClock();
        $limiter = new RateLimiter($this->redis, $clock, $this->prefix);
        $limiter->attempt('k', 1, 60);
        for ($i = 0; $i < 5; ++$i) {
            $clock->advance(10);
            self::assertFalse($limiter->attempt('k', 1, 60)->allowed);
        }

        $clock->advance(11); // 61 s after the only counted hit

        self::assertTrue($limiter->attempt('k', 1, 60)->allowed);
    }

    public function testSessionStoreRoundTripAndHashedKeys(): void
    {
        $store = new RedisSessionStore($this->redis, $this->prefix . 'sess:');
        $id = str_repeat('ab', 32);

        $store->write($id, ['user' => 5, 'nested' => ['a' => 1]], 60);

        self::assertSame(['user' => 5, 'nested' => ['a' => 1]], $store->read($id));
        self::assertFalse($this->redis->exists($this->prefix . 'sess:' . $id) === 1, 'raw id must not be the key');
        self::assertSame(1, $this->redis->exists($this->prefix . 'sess:' . hash('sha256', $id)));
        self::assertGreaterThan(0, $this->redis->ttl($this->prefix . 'sess:' . hash('sha256', $id)));
        $store->destroy($id);
        self::assertNull($store->read($id));
    }

    public function testSessionStoreIgnoresCorruptedData(): void
    {
        $store = new RedisSessionStore($this->redis, $this->prefix . 'sess:');
        $id = str_repeat('cd', 32);
        $this->redis->set($this->prefix . 'sess:' . hash('sha256', $id), 'not json');

        self::assertNull($store->read($id));
    }
}
