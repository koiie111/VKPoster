<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use App\Support\UserAgent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Device labels for the sessions list.
 */
#[CoversClass(UserAgent::class)]
final class UserAgentTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function agents(): array
    {
        return [
            'chrome windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36', 'Chrome на Windows'],
            'safari mac' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15', 'Safari на macOS'],
            'firefox linux' => ['Mozilla/5.0 (X11; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0', 'Firefox на Linux'],
            'edge' => ['Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/120.0 Safari/537.36 Edg/120.0', 'Edge на Windows'],
            'yandex' => ['Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/120.0 YaBrowser/24.1 Safari/537.36', 'Яндекс Браузер на Windows'],
            'iphone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1', 'Safari на iOS'],
            'android chrome' => ['Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/120.0 Mobile Safari/537.36', 'Chrome на Android'],
            'empty' => ['', 'Неизвестное устройство'],
            'curl' => ['curl/8.0', 'Браузер'],
        ];
    }

    #[DataProvider('agents')]
    public function testDescribe(string $agent, string $expected): void
    {
        self::assertSame($expected, UserAgent::describe($agent));
    }
}
