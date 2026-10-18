<?php

declare(strict_types=1);

namespace App\Integrations\Ai\Llm;

use App\Kernel\Exception\SsrfException;
use App\Kernel\HttpClient\HttpClientInterface;

/**
 * JSON POST to a model endpoint. The URL is configured by a person (owner or workspace), so every call goes through the SSRF guard
 * (`user_url`); redirects are not followed; the body is capped; the provider's error text is dropped (it may echo the prompt).
 * A paid POST is never replayed.
 */
final class LlmTransport
{
    private const MAX_BODY = 2097152;

    public function __construct(private readonly HttpClientInterface $http, private readonly int $timeout = 60)
    {
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     * @throws LlmException
     */
    public function post(string $url, array $headers, array $payload): array
    {
        try {
            $response = $this->http->request('POST', $url, [
                'headers' => $headers + ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
                'json' => $payload,
                'timeout' => $this->timeout,
                'connect_timeout' => 5,
                'follow_redirects' => false,
                'user_url' => true,
                'stream' => true,
            ]);
        } catch (SsrfException) {
            throw new LlmException('forbidden_url');
        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            throw new LlmException(str_contains($e->getMessage(), 'timed out') ? 'timeout' : 'unavailable');
        } catch (\Throwable) {
            throw new LlmException('unavailable');
        }
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $response->getBody()->close();
            throw new LlmException(match (true) {
                $status === 401 || $status === 403 => 'credentials',
                $status === 429 => 'rate_limited',
                $status >= 500 => 'unavailable',
                default => 'bad_request',
            });
        }
        $body = '';
        try {
            while (!$response->getBody()->eof()) {
                $body .= $response->getBody()->read(8192);
                if (strlen($body) > self::MAX_BODY) {
                    throw new LlmException('bad_response');
                }
            }
            $decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (LlmException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new LlmException('bad_response');
        } finally {
            $response->getBody()->close();
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new LlmException('bad_response');
        }

        return $decoded;
    }
}
