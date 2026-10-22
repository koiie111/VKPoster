<?php

declare(strict_types=1);

namespace App\Tests\Unit\Analytics;

use App\Domain\Analytics\{AnalyticsPdfKey,AnalyticsPdf};
use App\Kernel\Exception\HttpException;
use App\Tests\Support\{MockHttpClient,TestEnv};
use PHPUnit\Framework\TestCase;

final class PdfSecurityTest extends TestCase
{
    public function testDomainSeparatedKeyIsUsedForRendererAuthentication(): void
    {
        $key = AnalyticsPdfKey::derive(TestEnv::KEY);
        self::assertSame(hash_hmac('sha256', 'analytics-pdf-v1', TestEnv::KEY), $key);
        self::assertNotSame(TestEnv::KEY, $key);
        $http = new MockHttpClient();
        $http->expect('POST', 'http://analytics-pdf:3000/pdf', 200, '%PDF-test');
        $pdf = new AnalyticsPdf($http, TestEnv::config());
        self::assertSame('%PDF-test', $pdf->render('<h1>test</h1>'));
        self::assertSame(hash_hmac('sha256', '<h1>test</h1>', $key), $http->requests[0]['options']['headers']['X-Report-Signature']);
        self::assertStringNotContainsString(TestEnv::KEY, json_encode($http->requests, JSON_THROW_ON_ERROR));
    }

    public function testMissingMasterFailsClosedBeforeNetwork(): void
    {
        $http = new MockHttpClient();
        $pdf = new AnalyticsPdf(
            $http,
            new \App\Kernel\Config(
                ['security' => ['key' => ''],
            'analytics' => ['pdf_url' => 'http://analytics-pdf:3000']],
                new \App\Kernel\Env([])
            )
        );
        try {
            $pdf->render('html');
            self::fail('Missing key must fail closed');
        } catch (HttpException $error) {
            self::assertSame(503, $error->status);
        }
        self::assertSame([], $http->requests);
    }

    public function testRendererComposeBlocksNeverReceiveMasterOrPlatformCredentials(): void
    {
        foreach (['compose.yaml','compose.prod.yaml'] as $name) {
            $text = file_get_contents(dirname(__DIR__, 3).'/'.$name);
            self::assertIsString($text);
            self::assertSame(1, preg_match('/^  analytics-pdf:\n(.*?)(?=^  [a-z]|\z)/ms', $text, $match));
            $renderer = $match[1] ?? throw new \LogicException('Missing renderer block');
            foreach (['APP_KEY','env_file','TELEGRAM','VK_TOKEN','MAX_TOKEN','INSTAGRAM','<<: *app'] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $renderer);
            }
            if ($name === 'compose.prod.yaml') {
                self::assertStringContainsString('VAULT_PDF_VERIFY_URL', $renderer);
                self::assertStringContainsString('transit-pdf.token', $renderer);
                self::assertStringNotContainsString('ANALYTICS_PDF_KEY', $renderer);
            } else {
                self::assertStringContainsString('analytics-pdf-key:/run/analytics-pdf-key:ro', $renderer);
                self::assertStringContainsString('service_completed_successfully', $renderer);
            }
            self::assertStringContainsString('--queue=analytics', $text);
            self::assertStringContainsString('--queue=publish,default', $text);
        }
    }
}
