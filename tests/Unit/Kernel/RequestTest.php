<?php

declare(strict_types=1);

namespace App\Tests\Unit\Kernel;

use App\Kernel\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Request parsing, immutability and trusted-proxy handling.
 */
#[CoversClass(Request::class)]
final class RequestTest extends TestCase
{
    public function testJsonBodyIsParsed(): void
    {
        $request = Request::create('POST', '/x', headers: ['Content-Type' => 'application/json'], rawBody: '{"a":1}');

        self::assertSame(['a' => 1], $request->json());
        self::assertSame(1, $request->input('a'));
    }

    public function testBrokenJsonGivesEmptyBody(): void
    {
        $request = Request::create('POST', '/x', headers: ['content-type' => 'application/json'], rawBody: '{oops');

        self::assertSame([], $request->json());
    }

    public function testMethodOverrideOnlyFromPost(): void
    {
        self::assertSame('DELETE', Request::create('POST', '/x', body: ['_method' => 'delete'])->method);
        self::assertSame('GET', Request::create('GET', '/x', body: ['_method' => 'DELETE'])->method);
        self::assertSame('POST', Request::create('POST', '/x', body: ['_method' => 'TRACE'])->method);
    }

    public function testAttributesAreImmutable(): void
    {
        $a = Request::create('GET', '/');
        $b = $a->withAttribute('k', 'v');

        self::assertNull($a->attribute('k'));
        self::assertSame('v', $b->attribute('k'));
    }

    public function testForwardedHeadersAreIgnoredFromUntrustedPeers(): void
    {
        $request = Request::create(
            'GET',
            '/',
            headers: ['X-Forwarded-For' => '1.2.3.4', 'X-Forwarded-Proto' => 'https'],
            server: ['REMOTE_ADDR' => '203.0.113.9'],
            trustedProxies: ['10.0.0.0/8'],
        );

        self::assertSame('203.0.113.9', $request->ip());
        self::assertSame('http', $request->scheme());
    }

    public function testForwardedHeadersAreHonouredFromTrustedProxy(): void
    {
        $request = Request::create(
            'GET',
            '/',
            headers: ['X-Forwarded-For' => '198.51.100.7, 10.0.0.5', 'X-Forwarded-Proto' => 'https'],
            server: ['REMOTE_ADDR' => '10.0.0.2'],
            trustedProxies: ['10.0.0.0/8'],
        );

        self::assertSame('198.51.100.7', $request->ip());
        self::assertSame('https', $request->scheme());
        self::assertTrue($request->isSecure());
    }

    public function testClientCannotSpoofIpBehindTrustedProxy(): void
    {
        // The client prepends a fake address; the proxy appended the real one.
        $request = Request::create(
            'GET',
            '/',
            headers: ['X-Forwarded-For' => '6.6.6.6, 198.51.100.7'],
            server: ['REMOTE_ADDR' => '10.0.0.2'],
            trustedProxies: ['10.0.0.2'],
        );

        self::assertSame('198.51.100.7', $request->ip());
    }

    public function testWantsJsonForApiPathsAndAcceptHeader(): void
    {
        self::assertTrue(Request::create('GET', '/api/v1/x')->wantsJson());
        self::assertTrue(Request::create('GET', '/x', headers: ['Accept' => 'application/json'])->wantsJson());
        self::assertFalse(Request::create('GET', '/x')->wantsJson());
    }

    public function testFromGlobalsBuildsRequestFromSuperglobals(): void
    {
        $saved = [$_SERVER, $_GET, $_POST, $_COOKIE, $_FILES];
        $tmp = tempnam(sys_get_temp_dir(), 'up');
        self::assertIsString($tmp);
        file_put_contents($tmp, 'hello');
        try {
            $_SERVER = [
                'REQUEST_METHOD' => 'post',
                'REQUEST_URI' => '/a/b?x=1',
                'REMOTE_ADDR' => '10.0.0.9',
                'HTTP_HOST' => 'App.Test',
                'HTTP_X_FORWARDED_PROTO' => 'https',
                'CONTENT_TYPE' => 'multipart/form-data',
                'HTTPS' => 'off',
            ];
            $_GET = ['x' => '1'];
            $_POST = ['name' => 'ann'];
            $_COOKIE = ['sid' => 'abc'];
            $_FILES = [
                'one' => ['name' => 'a.txt', 'tmp_name' => $tmp, 'size' => 5, 'error' => UPLOAD_ERR_OK],
                'many' => ['name' => ['b.txt', 'c.txt'], 'tmp_name' => ['', ''], 'size' => [0, 0], 'error' => [UPLOAD_ERR_NO_FILE, UPLOAD_ERR_NO_FILE]],
                'junk' => 'not an array',
            ];

            $request = Request::fromGlobals(['10.0.0.0/8']);
        } finally {
            [$_SERVER, $_GET, $_POST, $_COOKIE, $_FILES] = $saved;
        }

        self::assertSame('POST', $request->method);
        self::assertSame('/a/b', $request->path);
        self::assertSame(['x' => '1'], $request->query);
        self::assertSame('ann', $request->input('name'));
        self::assertSame('abc', $request->cookie('sid'));
        self::assertSame('app.test', $request->host());
        self::assertSame('https', $request->scheme());
        self::assertSame('multipart/form-data', $request->header('content-type'));
        $one = $request->file('one');
        self::assertNotNull($one);
        self::assertTrue($one->isValid());
        self::assertSame('text/plain', $one->mimeType());
        self::assertNull($request->file('many'), 'multi-file inputs are lists, not single files');
        self::assertIsArray($request->files['many']);
        self::assertCount(2, $request->files['many']);
        self::assertArrayNotHasKey('junk', $request->files);
        unlink($tmp);
    }

    public function testInvalidUploadsAreNeverTrusted(): void
    {
        $file = new \App\Kernel\Http\UploadedFile('x.php', '/nonexistent', 10, UPLOAD_ERR_OK);
        $failed = new \App\Kernel\Http\UploadedFile('x.png', '', 0, UPLOAD_ERR_INI_SIZE);

        self::assertFalse($file->isValid());
        self::assertNull($file->mimeType());
        self::assertFalse($failed->isValid());
    }

    public function testIpv6RangesAndGarbageInProxyList(): void
    {
        $request = Request::create(
            'GET',
            '/',
            headers: ['X-Forwarded-For' => '2001:db8::5'],
            server: ['REMOTE_ADDR' => 'fd00::2'],
            trustedProxies: ['fd00::/8', 'not-an-ip', '10.0.0.0/33x'],
        );

        self::assertSame('2001:db8::5', $request->ip());
        self::assertSame('0.0.0.0', Request::create('GET', '/')->ip());
    }

    public function testMalformedForwardedForFallsBackToPeer(): void
    {
        $request = Request::create(
            'GET',
            '/',
            headers: ['X-Forwarded-For' => 'garbage'],
            server: ['REMOTE_ADDR' => '10.0.0.2'],
            trustedProxies: ['10.0.0.2'],
        );

        self::assertSame('10.0.0.2', $request->ip());
    }

    public function testAllMergesQueryAndBodyWithQueryWinningOnConflict(): void
    {
        $request = Request::create('POST', '/', ['a' => 'q'], ['a' => 'b', 'c' => 'd']);

        self::assertSame(['a' => 'q', 'c' => 'd'], $request->all());
        self::assertTrue(Request::create('OPTIONS', '/')->isReadMethod());
        self::assertFalse($request->isReadMethod());
    }
}
