<?php

declare(strict_types=1);

namespace App\Tests\Feature\Media;

use App\Domain\Media\MediaFetcher;
use App\Kernel\HttpClient\GuzzleHttpClient;
use App\Kernel\HttpClient\HttpClientInterface;
use App\Kernel\HttpClient\SsrfGuard;
use App\Tests\Support\MediaFixtures;
use App\Tests\Support\MediaTestCase;
use App\Tests\Support\MockHttpClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response as PsrResponse;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(MediaFetcher::class)]
final class MediaUrlUploadTest extends MediaTestCase
{
    private MockHttpClient $http;

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = new MockHttpClient();
        $this->app->container()->instance(HttpClientInterface::class, $this->http);
    }

    /**
     * @return array{response: \App\Kernel\Http\Response, data: array<string, mixed>}
     */
    private function byUrl(string $url): array
    {
        [, $workspace] = [null, $this->db->select('SELECT id, public_id FROM workspaces')[0]];
        $response = $this->post('/w/' . $workspace['public_id'] . '/media/upload-url', ['url' => $url], ['Accept' => 'application/json']);
        $data = json_decode($response->body, true);
        self::assertIsArray($data);

        return ['response' => $response, 'data' => $data];
    }

    public function testDownloadsAndStoresAPicture(): void
    {
        $this->ownerSession();
        $this->http->expect('GET', 'https://example.com/pics/cat.jpg', 200, MediaFixtures::jpeg(64, 32), ['Content-Type' => 'image/jpeg']);

        $result = $this->byUrl('https://example.com/pics/cat.jpg');

        self::assertSame(200, $result['response']->status, $result['response']->body);
        self::assertSame('cat.jpg', $result['data']['items'][0]['name']);
        self::assertSame('64×32', $result['data']['items'][0]['dimensions']);
        $this->http->assertAllConsumed();
        self::assertTrue($this->http->requests[0]['options']['user_url'], 'the request goes through the SSRF guard');
        self::assertArrayHasKey('timeout', $this->http->requests[0]['options']);
    }

    public function testTheContentIsJudgedByItsBytesNotByTheUrlOrServerHeader(): void
    {
        $this->ownerSession();
        $this->http->expect('GET', 'https://example.com/a.jpg', 200, '<?php echo 1;', ['Content-Type' => 'image/jpeg']);

        $result = $this->byUrl('https://example.com/a.jpg');

        self::assertSame(422, $result['response']->status);
        self::assertSame([], $this->storage->objects);
    }

    public function testErrorStatusAndMissingUrl(): void
    {
        $this->ownerSession();
        $this->http->expect('GET', 'https://example.com/missing.jpg', 404, 'nope');

        $notFound = $this->byUrl('https://example.com/missing.jpg');
        $empty = $this->byUrl('');

        self::assertSame(422, $notFound['response']->status);
        self::assertStringContainsString('кодом 404', $notFound['data']['errors'][0]);
        self::assertSame(422, $empty['response']->status);
    }

    public function testDeclaredAndActualOversizeAreRefused(): void
    {
        $this->ownerSession();
        $this->http->expect('GET', 'https://example.com/declared.mp4', 200, 'x', ['Content-Length' => (string) (60 * 1024 * 1024)]);
        $this->http->expect('GET', 'https://example.com/actual.bin', 200, str_repeat('x', 50 * 1024 * 1024 + 10));

        $declared = $this->byUrl('https://example.com/declared.mp4');
        $actual = $this->byUrl('https://example.com/actual.bin');

        self::assertSame(422, $declared['response']->status);
        self::assertStringContainsString('слишком большой', $declared['data']['errors'][0]);
        self::assertSame(422, $actual['response']->status);
        self::assertStringContainsString('слишком большой', $actual['data']['errors'][0]);
        self::assertSame([], glob(\App\Tests\Support\TestEnv::basePath() . '/storage/tmp/media*'), 'no temp files are left behind');
    }

    /**
     * The real HTTP client with the real guard (a Guzzle mock handler stands behind it): private targets never get a request.
     *
     * @return iterable<string, array{string}>
     */
    public static function forbiddenUrls(): iterable
    {
        yield 'loopback' => ['http://127.0.0.1/a.jpg'];
        yield 'localhost ip6' => ['http://[::1]/a.jpg'];
        yield 'private 10.x' => ['http://10.0.0.5/a.jpg'];
        yield 'private 192.168.x' => ['http://192.168.1.1/a.jpg'];
        yield 'cloud metadata' => ['http://169.254.169.254/latest/meta-data/'];
        yield 'mapped ipv6' => ['http://[::ffff:10.0.0.1]/a.jpg'];
        yield 'file scheme' => ['file:///etc/passwd'];
        yield 'ftp scheme' => ['ftp://example.com/a.jpg'];
        yield 'credentials' => ['http://user:pass@example.com/a.jpg'];
        yield 'odd port' => ['http://example.com:22/a.jpg'];
        yield 'host resolving to private' => ['http://internal.example.test/a.jpg'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('forbiddenUrls')]
    public function testSsrfTargetsAreRefusedBeforeAnyRequest(string $url): void
    {
        $this->ownerSession();
        $reached = false;
        $handler = new MockHandler([static function () use (&$reached): PsrResponse {
            $reached = true;

            return new PsrResponse(200, [], MediaFixtures::jpeg());
        }]);
        $guard = new SsrfGuard(static fn (string $host): array => $host === 'internal.example.test' ? ['10.1.2.3'] : ['93.184.216.34']);
        $this->app->container()->instance(HttpClientInterface::class, new GuzzleHttpClient($guard, null, new Client(['handler' => $handler])));
        // The fetcher is built from the container, so replace it too.
        $this->app->container()->instance(MediaFetcher::class, $this->app->container()->make(MediaFetcher::class));

        $result = $this->byUrl($url);

        self::assertSame(422, $result['response']->status, $result['response']->body);
        self::assertFalse($reached, 'no request may leave for ' . $url);
        self::assertSame([], $this->storage->objects);
        self::assertStringNotContainsString('127.0.0.1', $result['response']->body, 'the message does not echo internals');
    }

    public function testRedirectToAPrivateAddressIsRefused(): void
    {
        $this->ownerSession();
        $handler = new MockHandler([
            new PsrResponse(302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
            new PsrResponse(200, [], 'secret'),
        ]);
        $guard = new SsrfGuard(static fn (string $host): array => ['93.184.216.34']);
        $this->app->container()->instance(HttpClientInterface::class, new GuzzleHttpClient($guard, null, new Client(['handler' => $handler])));
        $this->app->container()->instance(MediaFetcher::class, $this->app->container()->make(MediaFetcher::class));

        $result = $this->byUrl('https://example.com/redirect.jpg');

        self::assertSame(422, $result['response']->status);
        self::assertSame(1, $handler->count(), 'the second hop was never requested');
    }

    public function testUrlUploadIsRateLimitedAndNeedsRights(): void
    {
        [, $workspace] = $this->ownerWithWorkspace();
        $viewer = $this->memberOf($workspace, 'viewer@example.com', \App\Domain\Workspace\Role::Viewer);
        $this->actAs($viewer);

        $response = $this->post('/w/' . $workspace->publicId . '/media/upload-url', ['url' => 'https://example.com/a.jpg'], ['Accept' => 'application/json']);

        self::assertSame(403, $response->status);
    }
}
