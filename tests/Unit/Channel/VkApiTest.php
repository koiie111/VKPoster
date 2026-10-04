<?php

declare(strict_types=1);

namespace App\Tests\Unit\Channel;

use App\Integrations\Social\Contracts\ErrorKind;
use App\Integrations\Social\Contracts\PlatformError;
use App\Integrations\Social\Vk\VkApi;
use App\Integrations\Social\Vk\VkRateGate;
use App\Tests\Support\MockHttpClient;
use App\Tests\Support\TestEnv;
use App\Tests\Support\VkFixtures;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(VkApi::class)]
#[CoversClass(VkRateGate::class)]
final class VkApiTest extends TestCase
{
    private MockHttpClient $http;
    private VkApi $api;

    protected function setUp(): void
    {
        $this->http = new MockHttpClient();
        $this->api = new VkApi($this->http, new VkRateGate());
    }

    public function testTheTokenTravelsInTheHeaderNotInTheUrlOrBody(): void
    {
        $this->http->expect('POST', VkFixtures::API . 'wall.post', 200, VkFixtures::raw('wall_post'));
        $result = $this->api->call('wall.post', ['owner_id' => -777, 'from_group' => true, 'attachments' => ['a', 'b']], VkFixtures::TOKEN);

        self::assertSame(4321, $result['post_id']);
        $request = $this->http->requests[0];
        self::assertSame('Bearer ' . VkFixtures::TOKEN, $request['options']['headers']['Authorization']);
        self::assertStringNotContainsString(VkFixtures::TOKEN, $request['url']);
        self::assertStringNotContainsString(VkFixtures::TOKEN, json_encode($request['options']['form_params'], JSON_THROW_ON_ERROR));
        self::assertSame('5.199', $request['options']['form_params']['v']);
        self::assertSame('1', $request['options']['form_params']['from_group'], 'booleans are sent as 0 and 1');
        self::assertSame('["a","b"]', $request['options']['form_params']['attachments']);
    }

    /**
     * @return iterable<string, array{int, ErrorKind, ?int}>
     */
    public static function errors(): iterable
    {
        yield 'auth 5' => [5, ErrorKind::Auth, null];
        yield 'no rights 15' => [15, ErrorKind::Auth, null];
        yield 'no rights 27' => [27, ErrorKind::Auth, null];
        yield 'too many 6' => [6, ErrorKind::RateLimited, 2];
        yield 'flood 9' => [9, ErrorKind::RateLimited, 60];
        yield 'method limit 29' => [29, ErrorKind::RateLimited, 600];
        yield 'captcha 14' => [14, ErrorKind::Permanent, null];
        yield 'refused 214' => [214, ErrorKind::Permanent, null];
        yield 'params 100' => [100, ErrorKind::Permanent, null];
        yield 'internal 10' => [10, ErrorKind::Temporary, null];
        yield 'unknown 777' => [777, ErrorKind::Temporary, null];
        yield 'something else' => [3, ErrorKind::Permanent, null];
    }

    #[DataProvider('errors')]
    public function testApiErrorsAreClassified(int $code, ErrorKind $kind, ?int $retryAfter): void
    {
        $this->http->expect('POST', VkFixtures::API . 'wall.post', 200, VkFixtures::error($code, 'secret internals'));
        try {
            $this->api->call('wall.post', [], VkFixtures::TOKEN, true);
            self::fail('expected a PlatformError');
        } catch (PlatformError $e) {
            self::assertSame($kind, $e->kind);
            self::assertSame($retryAfter, $e->retryAfter);
            self::assertSame($code, $e->platformCode);
            self::assertNotSame('', $e->forUser());
            self::assertStringNotContainsString('internals', $e->forUser());
            self::assertStringNotContainsString(VkFixtures::TOKEN, $e->getMessage());
        }
    }

    public function testACaptchaAndALimitHaveHumanTexts(): void
    {
        foreach ([14 => 'капч', 214 => 'лимит'] as $code => $word) {
            $this->http->expect('POST', VkFixtures::API . 'wall.post', 200, VkFixtures::error($code));
            try {
                $this->api->call('wall.post', [], VkFixtures::TOKEN, true);
            } catch (PlatformError $e) {
                self::assertStringContainsString($word, $e->forUser());
            }
        }
    }

    public function testALostConnectionIsUnknownOnlyForPublishingCalls(): void
    {
        $failing = new class () implements \App\Kernel\HttpClient\HttpClientInterface {
            public function request(string $method, string $url, array $options = []): \Psr\Http\Message\ResponseInterface
            {
                throw new RequestException('timeout with ' . ($options['headers']['Authorization'] ?? ''), new Request($method, $url));
            }
        };
        $api = new VkApi($failing, new VkRateGate());

        try {
            $api->call('wall.post', [], VkFixtures::TOKEN, true);
            self::fail();
        } catch (PlatformError $e) {
            self::assertSame(ErrorKind::UnknownOutcome, $e->kind);
            self::assertStringNotContainsString(VkFixtures::TOKEN, $e->getMessage(), 'the token never reaches an error message');
            self::assertNull($e->getPrevious());
        }
        try {
            $api->call('groups.get', [], VkFixtures::TOKEN);
            self::fail();
        } catch (PlatformError $e) {
            self::assertSame(ErrorKind::Temporary, $e->kind);
        }
    }

    public function testAConnectionThatNeverOpenedIsAlwaysTemporary(): void
    {
        $failing = new class () implements \App\Kernel\HttpClient\HttpClientInterface {
            public function request(string $method, string $url, array $options = []): \Psr\Http\Message\ResponseInterface
            {
                throw new ConnectException('refused', new Request($method, $url));
            }
        };
        $this->expectException(PlatformError::class);
        try {
            (new VkApi($failing, new VkRateGate()))->call('wall.post', [], VkFixtures::TOKEN, true);
        } catch (PlatformError $e) {
            self::assertSame(ErrorKind::Temporary, $e->kind);

            throw $e;
        }
    }

    public function testAnUnreadableAnswerFromAGateway(): void
    {
        $this->http->expect('POST', VkFixtures::API . 'wall.post', 502, '<html>bad gateway</html>');
        $this->http->expect('POST', VkFixtures::API . 'groups.get', 502, '<html>bad gateway</html>');
        try {
            $this->api->call('wall.post', [], VkFixtures::TOKEN, true);
            self::fail();
        } catch (PlatformError $e) {
            self::assertSame(ErrorKind::UnknownOutcome, $e->kind, 'a publish that ended in a gateway error may have gone through');
        }
        try {
            $this->api->call('groups.get', [], VkFixtures::TOKEN);
            self::fail();
        } catch (PlatformError $e) {
            self::assertSame(ErrorKind::Temporary, $e->kind);
        }
    }

    public function testUploadsOnlyGoToVkHostsOverHttps(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'vk');
        file_put_contents($file, 'x');
        foreach (['http://pu.vk.com/up', 'https://evil.example/up', 'https://vk.com.evil.example/up', 'file:///etc/passwd'] as $url) {
            try {
                $this->api->upload($url, 'photo', $file, 'a.jpg');
                self::fail('upload to ' . $url . ' must be refused');
            } catch (PlatformError $e) {
                self::assertSame(ErrorKind::Permanent, $e->kind);
            }
        }
        self::assertSame([], $this->http->requests);

        $this->http->expect('POST', 'https://pu.vk.com/c1/upload.php', 200, VkFixtures::raw('photo_uploaded'));
        self::assertSame('abc123', $this->api->upload('https://pu.vk.com/c1/upload.php', 'photo', $file, 'a.jpg')['hash']);

        $this->http->expect('POST', 'https://pu.vk.com/c1/upload.php', 200, '{"error":"bad file"}');
        try {
            $this->api->upload('https://pu.vk.com/c1/upload.php', 'photo', $file, 'a.jpg');
            self::fail();
        } catch (PlatformError $e) {
            self::assertSame(ErrorKind::Permanent, $e->kind);
        }
        unlink($file);
    }

    public function testTheRateGateAllowsThreeCallsASecond(): void
    {
        $redis = TestEnv::redis();
        $redis->flushDB();
        $gate = new VkRateGate($redis, 3);
        $start = microtime(true);
        for ($i = 0; $i < 4; ++$i) {
            $gate->wait('token-a');
        }
        $elapsed = microtime(true) - $start;
        // Three fit in one second; the fourth has to wait for the next one (unless the loop straddled a second boundary).
        $keys = $redis->keys('vk:rate:*');
        self::assertNotEmpty($keys);
        self::assertStringNotContainsString('token-a', implode('', $keys), 'the token is only hashed into the key');
        self::assertLessThan(2.5, $elapsed);
        $redis->flushDB();
    }
}
