<?php

declare(strict_types=1);

namespace App\Tests\Unit\Kernel;

use App\Kernel\Exception\SsrfException;
use App\Kernel\HttpClient\GuzzleHttpClient;
use App\Kernel\HttpClient\SsrfGuard;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * SSRF protection: forbidden targets, DNS results, and redirects into private networks.
 */
#[CoversClass(SsrfGuard::class)]
#[CoversClass(GuzzleHttpClient::class)]
final class SsrfGuardTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function forbidden(): array
    {
        return [
            'loopback' => ['http://127.0.0.1/'],
            'loopback other' => ['http://127.1.2.3/x'],
            'ipv6 loopback' => ['http://[::1]/'],
            'private 10' => ['http://10.0.0.5/'],
            'private 172' => ['http://172.16.0.1/'],
            'private 192' => ['http://192.168.1.1/'],
            'metadata' => ['http://169.254.169.254/latest/meta-data/'],
            'carrier nat' => ['http://100.64.0.1/'],
            'unspecified' => ['http://0.0.0.0/'],
            'ipv4 mapped ipv6' => ['http://[::ffff:10.0.0.1]/'],
            'ipv6 unique local' => ['http://[fd00::1]/'],
            'ipv6 link local' => ['http://[fe80::1]/'],
            'file scheme' => ['file:///etc/passwd'],
            'gopher' => ['gopher://example.com/'],
            'credentials' => ['http://user:pass@example.com/'],
            'odd port' => ['http://example.com:22/'],
            'no host' => ['http:///x'],
            'decimal ip' => ['http://2130706433/'],
            'resolves to private' => ['http://internal.example.com/'],
            'resolves to nothing' => ['http://nxdomain.example.com/'],
        ];
    }

    private function guard(): SsrfGuard
    {
        return new SsrfGuard(static fn (string $host): array => match ($host) {
            'internal.example.com' => ['10.1.2.3'],
            'mixed.example.com' => ['93.184.216.34', '127.0.0.1'],
            'example.com' => ['93.184.216.34'],
            default => [],
        });
    }

    #[DataProvider('forbidden')]
    public function testForbiddenTargetsAreRejected(string $url): void
    {
        $this->expectException(SsrfException::class);
        $this->guard()->assertSafe($url);
    }

    public function testEveryResolvedAddressMustBePublic(): void
    {
        $this->expectException(SsrfException::class);
        $this->guard()->assertSafe('http://mixed.example.com/');
    }

    public function testPublicTargetIsAcceptedAndPinned(): void
    {
        $target = $this->guard()->assertSafe('https://example.com/feed.xml');

        self::assertSame('93.184.216.34', $target['ip']);
        self::assertSame(443, $target['port']);
        self::assertSame('example.com', $target['host']);
    }

    private function client(MockHandler $mock): GuzzleHttpClient
    {
        return new GuzzleHttpClient($this->guard(), null, new Client(['handler' => HandlerStack::create($mock)]));
    }

    public function testRedirectToPrivateAddressIsBlocked(): void
    {
        $mock = new MockHandler([
            new Response(302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
            new Response(200, [], 'secret'),
        ]);

        $this->expectException(SsrfException::class);
        try {
            $this->client($mock)->request('GET', 'https://example.com/redir', ['user_url' => true]);
        } finally {
            self::assertCount(1, $mock, 'the second hop must never be requested');
        }
    }

    public function testRelativeRedirectsAreFollowedAndRevalidated(): void
    {
        $mock = new MockHandler([
            new Response(301, ['Location' => '/final']),
            new Response(200, [], 'ok'),
        ]);

        $response = $this->client($mock)->request('GET', 'https://example.com/start', ['user_url' => true]);

        self::assertSame('ok', (string) $response->getBody());
    }

    public function testRedirectLoopsAreCut(): void
    {
        $mock = new MockHandler(array_fill(0, 10, new Response(302, ['Location' => '/again'])));

        $this->expectException(SsrfException::class);
        $this->client($mock)->request('GET', 'https://example.com/a', ['user_url' => true]);
    }

    public function testTrustedUrlsSkipTheGuard(): void
    {
        $mock = new MockHandler([new Response(200, [], 'ok')]);

        $response = $this->client($mock)->request('GET', 'http://10.0.0.1/internal-api');

        self::assertSame(200, $response->getStatusCode());
    }
}
