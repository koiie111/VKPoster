<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Kernel\HttpClient\HttpClientInterface;
use GuzzleHttp\Psr7\Response;
use LogicException;
use Psr\Http\Message\ResponseInterface;

/**
 * Test double for outgoing HTTP: replies only to requests registered with `expect()` and fails the
 * test on anything else, so no test can silently reach the internet.
 */
final class MockHttpClient implements HttpClientInterface
{
    /** @var list<array{method: string, url: string, response: ResponseInterface}> */
    private array $expectations = [];

    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    public array $requests = [];

    /**
     * @param array<string, string> $headers
     */
    public function expect(string $method, string $url, int $status = 200, string $body = '', array $headers = []): self
    {
        $this->expectations[] = [
            'method' => strtoupper($method),
            'url' => $url,
            'response' => new Response($status, $headers, $body),
        ];

        return $this;
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $this->requests[] = ['method' => strtoupper($method), 'url' => $url, 'options' => $options];
        foreach ($this->expectations as $i => $expectation) {
            if ($expectation['method'] === strtoupper($method) && $expectation['url'] === $url) {
                array_splice($this->expectations, $i, 1);

                return $expectation['response'];
            }
        }

        throw new LogicException(sprintf('Unexpected HTTP request in test: %s %s', strtoupper($method), $url));
    }

    public function assertAllConsumed(): void
    {
        if ($this->expectations !== []) {
            throw new LogicException(sprintf('%d expected HTTP request(s) were never made.', count($this->expectations)));
        }
    }
}
