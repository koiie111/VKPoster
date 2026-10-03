<?php

declare(strict_types=1);

namespace App\Tests\Unit\Kernel;

use App\Kernel\Http\Response;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Response factories, cookies and open-redirect protection.
 */
#[CoversClass(Response::class)]
final class ResponseTest extends TestCase
{
    public function testRedirectToRelativeUrl(): void
    {
        $response = Response::redirect('/login?next=%2Fa');

        self::assertSame(302, $response->status);
        self::assertSame('/login?next=%2Fa', $response->header('location'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badTargets(): array
    {
        return [
            'absolute' => ['https://evil.example/'],
            'protocol-relative' => ['//evil.example/'],
            'backslash' => ['/\\evil.example'],
            'scheme-less host' => ['evil.example'],
            'javascript' => ['javascript:alert(1)'],
            'header injection' => ["/ok\r\nSet-Cookie: a=b"],
            'empty' => [''],
        ];
    }

    #[DataProvider('badTargets')]
    public function testRedirectRefusesUnsafeTargets(string $target): void
    {
        $this->expectException(InvalidArgumentException::class);
        Response::redirect($target);
    }

    public function testTrustedRedirectChecksHostAllowList(): void
    {
        $ok = Response::redirectToTrusted('https://id.vk.com/auth', ['id.vk.com']);
        self::assertSame('https://id.vk.com/auth', $ok->header('Location'));

        $this->expectException(InvalidArgumentException::class);
        Response::redirectToTrusted('https://id.vk.com.evil.example/', ['id.vk.com']);
    }

    public function testCookieAttributes(): void
    {
        $response = Response::text('x')->withCookie('sid', 'a b', 0, true);

        self::assertCount(1, $response->cookies);
        self::assertStringContainsString('sid=a%20b', $response->cookies[0]);
        self::assertStringContainsString('HttpOnly', $response->cookies[0]);
        self::assertStringContainsString('Secure', $response->cookies[0]);
        self::assertStringContainsString('SameSite=Lax', $response->cookies[0]);
        self::assertStringContainsString('Max-Age=0', Response::text('x')->withCookie('sid', '', -1)->cookies[0]);
    }

    public function testHeaderReplacementIsCaseInsensitive(): void
    {
        $response = Response::html('x')->withHeader('content-type', 'text/plain');

        self::assertSame('text/plain', $response->header('Content-Type'));
        self::assertCount(1, $response->headers);
    }

    public function testJsonResponse(): void
    {
        $response = Response::json(['a' => 'ü'], 201);

        self::assertSame(201, $response->status);
        self::assertSame('{"a":"ü"}', $response->body);
        self::assertSame('application/json', $response->header('content-type'));
        self::assertSame(404, $response->withStatus(404)->status);
        self::assertSame('y', $response->withBody('y')->body);
    }
}
