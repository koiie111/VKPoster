<?php

declare(strict_types=1);

namespace App\Tests\Unit\Kernel;

use App\Kernel\Env;
use App\Kernel\Exception\ConfigException;
use App\Kernel\Exception\SsrfException;
use App\Kernel\HttpClient\EgressRoutes;
use App\Kernel\HttpClient\GuzzleHttpClient;
use App\Kernel\HttpClient\SsrfGuard;
use App\Integrations\Social\Contracts\ErrorKind;
use App\Integrations\Social\Contracts\PlatformError;
use App\Integrations\Social\Telegram\TelegramClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\ConnectException;
use Monolog\Logger;
use Monolog\Handler\TestHandler;
use PHPUnit\Framework\TestCase;

/** Routing uses the existing Guzzle transport; failures never add a second send/retry pipeline. */
final class EgressRoutesTest extends TestCase
{
    private function routes(): EgressRoutes
    {
        return new EgressRoutes(new Env(['EGRESS_TELEGRAM_MODE' => 'tunnel', 'EGRESS_TELEGRAM_PROXY_URL' => 'socks5h://fixture-user:fixture-password@127.0.0.1:1080']));
    }

    public function testOnlyTelegramUsesProxyAndDirectDisablesAmbientProxy(): void
    {
        $history = [];
        $handler = HandlerStack::create(new MockHandler([new Response(200), new Response(200)]));
        $handler->push(Middleware::history($history));
        $transport = new GuzzleHttpClient(new SsrfGuard(), null, new Client(['handler' => $handler]), $this->routes());
        $transport->request('GET', 'https://api.telegram.org/');
        $transport->request('GET', 'https://api.vk.com/');
        self::assertIsArray($history);
        self::assertCount(2, $history);
        self::assertSame('socks5h://fixture-user:fixture-password@127.0.0.1:1080', $history[0]['options']['proxy']);
        self::assertSame('', $history[1]['options']['proxy']);
        self::assertSame('direct', $this->routes()->route('max')['mode']);
    }

    public function testUserDownloadCannotUseProxyRemoteDnsToBypassSsrfPinning(): void
    {
        $transport = new GuzzleHttpClient(new SsrfGuard(), null, new Client(['handler' => new MockHandler([])]), $this->routes());
        $this->expectException(SsrfException::class);
        $transport->request('GET', 'https://api.telegram.org/private', ['user_url' => true]);
    }

    public function testProxyFailureIsSafeRetryableWithoutCredentialsOrDuplicateDispatch(): void
    {
        $history = [];
        $handler = HandlerStack::create(new MockHandler([new ConnectException('fixture-user:fixture-password', new Request('POST', 'https://api.telegram.org'))]));
        $handler->push(Middleware::history($history));
        $logs = new TestHandler();
        $logger = new Logger('egress', [$logs]);
        $transport = new GuzzleHttpClient(new SsrfGuard(), $logger, new Client(['handler' => $handler]), $this->routes());
        try {
            (new TelegramClient($transport, 'synthetic-bot-token'))->sendMessage(1, 'synthetic');
            self::fail('Unavailable egress must not succeed');
        } catch (PlatformError $error) {
            self::assertSame(ErrorKind::Temporary, $error->kind);
            self::assertStringNotContainsString('fixture-password', $error->getMessage());
        }
        self::assertIsArray($history);
        self::assertCount(1, $history);
        self::assertStringNotContainsString('fixture-password', json_encode($logs->getRecords(), JSON_THROW_ON_ERROR));
    }

    public function testUncertainProxyTimeoutNeverBecomesSafeToResendPublication(): void
    {
        $handler = new MockHandler([new \GuzzleHttp\Exception\NetworkTimeoutException('proxy timeout', new Request('POST', 'https://api.telegram.org'))]);
        $transport = new GuzzleHttpClient(new SsrfGuard(), null, new Client(['handler' => $handler]), $this->routes());
        try {
            (new TelegramClient($transport, 'synthetic-bot-token'))->sendMessage(1, 'synthetic');
            self::fail('An uncertain result must not succeed');
        } catch (PlatformError $error) {
            self::assertSame(ErrorKind::UnknownOutcome, $error->kind);
        }
    }

    public function testPublicPlaintextProxyCredentialsAreRejectedWithoutEchoingThem(): void
    {
        try {
            new EgressRoutes(new Env(['EGRESS_TELEGRAM_MODE' => 'socks5', 'EGRESS_TELEGRAM_PROXY_URL' => 'socks5://fixture-user:fixture-password@8.8.8.8:1080']));
            self::fail('Insecure proxy auth must be rejected');
        } catch (ConfigException $error) {
            self::assertStringNotContainsString('fixture-password', $error->getMessage());
        }
    }

    public function testCrossHostRedirectCannotForwardCredentialBearingPost(): void
    {
        $handler = new MockHandler([new Response(307, ['Location' => 'https://foreign.example/'])]);
        $transport = new GuzzleHttpClient(new SsrfGuard(), null, new Client(['handler' => $handler]));
        $this->expectException(SsrfException::class);
        $transport->request('POST', 'https://api.example/', ['headers' => ['Authorization' => 'Bearer fixture']]);
    }
}
