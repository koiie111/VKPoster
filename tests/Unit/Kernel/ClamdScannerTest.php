<?php

declare(strict_types=1);

namespace App\Tests\Unit\Kernel;

use App\Kernel\Exception\HttpException;
use App\Kernel\Security\ClamdScanner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** INSTREAM protocol fixtures verify clean/infected/unavailable decisions without a real network. */
final class ClamdScannerTest extends TestCase
{
    /** @return iterable<string, array{string, int}> */
    public static function responses(): iterable
    {
        yield 'clean' => ["stream: OK\0", 0];
        yield 'infected' => ["stream: Eicar-Signature FOUND\0", 422];
        yield 'limit error' => ["INSTREAM size limit exceeded. ERROR\0", 503];
        yield 'invalid' => ["stream: UNKNOWN\0", 503];
        yield 'unterminated' => ['stream: OK', 503];
    }

    #[DataProvider('responses')]
    public function testProtocolIsFailClosed(string $response, int $status): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);
        fwrite($pair[1], $response);
        stream_socket_shutdown($pair[1], STREAM_SHUT_WR);
        $scanner = new ClamdScanner('unix:///fixture/clamd.sock', 1024, 1, static fn () => $pair[0]);
        try {
            $scanner->scanBytes('synthetic-file');
            self::assertSame(0, $status);
            $wire = fread($pair[1], 1024);
            self::assertSame("zINSTREAM\0" . pack('N', 14) . 'synthetic-file' . pack('N', 0), $wire);
        } catch (HttpException $error) {
            self::assertSame($status, $error->status);
            self::assertStringNotContainsString('Eicar', $error->getMessage());
        } finally {
            fclose($pair[1]);
        }
    }

    public function testScannerUnavailableNeverPermitsUpload(): void
    {
        $scanner = new ClamdScanner('unix:///missing-stage19-fixture.sock');
        $this->expectException(HttpException::class);
        $scanner->scanBytes('fixture');
    }

    public function testOversizeRefusedBeforeConnecting(): void
    {
        $scanner = new ClamdScanner('unix:///fixture/clamd.sock', 3, 1, static function () {
            throw new \LogicException('Must not connect');
        });
        try {
            $scanner->scanBytes('four');
            self::fail('Oversize must fail');
        } catch (HttpException $error) {
            self::assertSame(413, $error->status);
        }
    }
}
