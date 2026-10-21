<?php

declare(strict_types=1);

namespace App\Tests\Unit\Kernel;

use App\Kernel\Operations\GlitchTipReporter;
use App\Tests\Support\FakeClock;
use App\Tests\Support\MockHttpClient;
use App\Tests\Support\TestEnv;
use PHPUnit\Framework\TestCase;

/** Reporting sends a small Sentry-compatible envelope and never raw exception/request data. */
final class GlitchTipTest extends TestCase
{
    public function testLoggerRedactionPreservesOnlySafeExceptionType(): void
    {
        $directory = sys_get_temp_dir() . '/reporting-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {
            $http = new MockHttpClient();
            $http->expect('POST', 'https://errors.example/api/1/store/', 200, '{}');
            $reporter = new GlitchTipReporter($http, TestEnv::config(['SENTRY_DSN' => 'https://fixture@errors.example/1']), new FakeClock());
            $logger = \App\Kernel\Log\LoggerFactory::create($directory, 'error', false, $reporter);
            $logger->error('http.unhandled_exception', ['exception' => new \RuntimeException('private@example.com synthetic-secret')]);
            $logger->close();
            self::assertSame('RuntimeException', $http->requests[0]['options']['json']['tags']['exception_type']);
            self::assertStringNotContainsString('synthetic-secret', json_encode($http->requests[0]['options']['json'], JSON_THROW_ON_ERROR));
        } finally {
            $files = glob($directory . '/*');
            foreach ($files === false ? [] : $files as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    public function testSafeStorePayloadAndTransportFailure(): void
    {
        $http = new MockHttpClient();
        $http->expect('POST', 'https://errors.example/api/1/store/', 200, '{}');
        $reporter = new GlitchTipReporter($http, TestEnv::config(['SENTRY_DSN' => 'https://fixture@errors.example/1']), new FakeClock('2026-10-19 00:00:00'));
        $reporter->report('http.unhandled_exception', ['exception' => new \RuntimeException('private@example.com synthetic-secret'), 'Authorization' => 'private-token']);
        self::assertCount(1, $http->requests);
        $payload = json_encode($http->requests[0]['options']['json'], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('synthetic-secret', $payload);
        self::assertStringNotContainsString('private', $payload);
        self::assertStringContainsString('RuntimeException', $payload);
        self::assertSame(2, $http->requests[0]['options']['timeout']);
        $reporter->report('another.error', []); // Mock throws: reporter must swallow the transport failure.
        self::assertCount(2, $http->requests);
        (new GlitchTipReporter($http, TestEnv::config(), new FakeClock()))->report('disabled', []);
        self::assertCount(2, $http->requests);
    }
}
