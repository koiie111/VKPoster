<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Support\IpRange;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IpRange::class)]
final class IpRangeTest extends TestCase
{
    public function testIpv4Ranges(): void
    {
        self::assertTrue(IpRange::contains('185.71.76.5', '185.71.76.0/27'));
        self::assertTrue(IpRange::contains('185.71.76.31', '185.71.76.0/27'));
        self::assertFalse(IpRange::contains('185.71.76.32', '185.71.76.0/27'), 'one past the /27');
        self::assertTrue(IpRange::contains('77.75.156.11', '77.75.156.11'), 'a bare address is a range of one');
        self::assertFalse(IpRange::contains('77.75.156.12', '77.75.156.11'));
        self::assertTrue(IpRange::contains('77.75.154.200', '77.75.154.128/25'));
        self::assertFalse(IpRange::contains('77.75.154.127', '77.75.154.128/25'));
    }

    public function testIpv6Ranges(): void
    {
        self::assertTrue(IpRange::contains('2a02:5180:0:1::5', '2a02:5180::/32'));
        self::assertFalse(IpRange::contains('2a02:5181::1', '2a02:5180::/32'));
    }

    public function testFamiliesNeverMatchEachOtherAndGarbageIsRefused(): void
    {
        self::assertFalse(IpRange::contains('185.71.76.5', '2a02:5180::/32'));
        self::assertFalse(IpRange::contains('not-an-ip', '185.71.76.0/27'));
        self::assertFalse(IpRange::contains('185.71.76.5', 'garbage/27'));
        self::assertTrue(IpRange::containsAny('185.71.77.9', ['10.0.0.0/8', '185.71.77.0/27']));
        self::assertFalse(IpRange::containsAny('8.8.8.8', ['10.0.0.0/8']));
    }
}
