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

    public function testShortVideoNetworksUseTheirOwnEgressRoutes(): void
    {
        $vars = [];
        foreach (['instagram', 'tiktok', 'youtube'] as $provider) {
            $vars['EGRESS_' . strtoupper($provider) . '_MODE'] = 'tunnel';
            $vars['EGRESS_' . strtoupper($provider) . '_PROXY_URL'] = 'socks5h://127.0.0.1:1080';
        }
        $routes = new EgressRoutes(new Env($vars));
        self::assertSame('instagram', $routes->providerFor('https://graph.instagram.com/v21.0/me'));
        self::assertSame('tiktok', $routes->providerFor('https://open.tiktokapis.com/v2/post/publish/video/init/'));
        self::assertSame('youtube', $routes->providerFor('https://www.googleapis.com/upload/youtube/v3/videos'));
        self::assertSame('', $routes->providerFor('https://www.googleapis.com/calendar/v3/calendars'));
        $history = [];
        $handler = HandlerStack::create(new MockHandler([new Response(200), new Response(200), new Response(200)]));
        $handler->push(Middleware::history($history));
        $http = new GuzzleHttpClient(new SsrfGuard(), null, new Client(['handler' => $handler]), $routes);
        foreach (['https://graph.instagram.com/', 'https://open.tiktokapis.com/', 'https://youtube.googleapis.com/'] as $url) {
            $http->request('GET', $url);
        }
        self::assertIsArray($history);
        foreach ($history as $request) {
            self::assertSame('socks5h://127.0.0.1:1080', $request['options']['proxy']);
        }
    }

    public function testProductionRefusesDirectEgressForEnabledShortVideoNetworks(): void
    {
        foreach (['instagram', 'tiktok', 'youtube'] as $provider) {
            try {
                new EgressRoutes(new Env(['APP_ENV' => 'production', 'PLATFORMS_ENABLED' => $provider]));
                self::fail('Direct egress must be rejected for ' . $provider);
            } catch (ConfigException $e) {
                self::assertStringContainsString($provider, $e->getMessage());
            }
        }
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
    public function testTransportClassificationIsIdenticalForEveryRouteAndContainsNoProxySecrets(): void
    {
        foreach (['direct', 'http', 'socks5', 'tunnel'] as $mode) {
            foreach ([\GuzzleHttp\Exception\ConnectTimeoutException::class => ['pre_dispatch_timeout', true],
                ConnectException::class => ['pre_dispatch_connection', true],
                \GuzzleHttp\Exception\NetworkTimeoutException::class => ['transport_failed', false],
                \GuzzleHttp\Exception\RequestException::class => ['transport_failed', false]] as $type => [$category, $retryable]) {
                $routes = new EgressRoutes(new Env(['EGRESS_TELEGRAM_MODE' => $mode,
                    'EGRESS_TELEGRAM_PROXY_URL' => $mode === 'direct' ? '' : ($mode === 'http' ? 'http' : 'socks5h') . '://fixture-user:fixture-password@127.0.0.1:1080']));
                $handler = new MockHandler([new $type('fixture-password', new Request('POST', 'https://api.telegram.org'))]);
                $transport = new GuzzleHttpClient(new SsrfGuard(), null, new Client(['handler' => $handler]), $routes);
                try {
                    (new \App\Integrations\ContentProviders\ProviderHttp($transport))->json('POST', 'https://api.telegram.org', []);
                    self::fail('Transport must fail.');
                } catch (\App\Integrations\ContentProviders\ProviderException $error) {
                    self::assertSame($category, $error->category, $mode . ':' . $type);
                    self::assertSame($retryable, $error->retryable);
                    self::assertNull($error->getPrevious());
                    self::assertStringNotContainsString('fixture-password', serialize($error));
                }
            }
        }
    }

}
