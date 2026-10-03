<?php

declare(strict_types=1);

namespace App\Kernel\HttpClient;

use Psr\Http\Message\ResponseInterface;

/**
 * Outgoing HTTP. Every integration depends on this interface, never on Guzzle directly,
 * so tests can swap in `MockHttpClient` and nothing reaches the internet.
 */
interface HttpClientInterface
{
    /**
     * Options are Guzzle request options plus `user_url => true`, which routes the call through the
     * SSRF guard (required whenever the URL came from a user, RSS feed, or remote content).
     * Redirects are followed manually (max 5) and, for user URLs, re-validated at every hop.
     *
     * @param array<string, mixed> $options
     * @throws \App\Kernel\Exception\SsrfException for forbidden user URLs
     * @throws \GuzzleHttp\Exception\GuzzleException on transport errors
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface;
}
