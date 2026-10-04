<?php

declare(strict_types=1);

namespace App\Integrations\OAuth;

use App\Kernel\HttpClient\HttpClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Shared plumbing for providers that talk to their endpoints over HTTP: form POSTs and JSON GETs with
 * uniform error handling. Never logs request bodies (they hold codes and secrets).
 */
abstract class AbstractHttpProvider implements OAuthProvider
{
    public function __construct(protected readonly HttpClientInterface $http)
    {
    }

    /**
     * @param array<string, string> $form
     * @param array<string, string> $headers
     * @return array<string, mixed>
     * @throws OAuthException
     */
    protected function postForm(string $url, array $form, array $headers = []): array
    {
        return $this->send('POST', $url, ['form_params' => $form, 'headers' => $headers + ['Accept' => 'application/json']]);
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, mixed>
     * @throws OAuthException
     */
    protected function getJson(string $url, array $headers = []): array
    {
        return $this->send('GET', $url, ['headers' => $headers + ['Accept' => 'application/json']]);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function send(string $method, string $url, array $options): array
    {
        try {
            $response = $this->http->request($method, $url, $options + ['follow_redirects' => false]);
        } catch (GuzzleException $e) {
            throw new OAuthException($this->id() . ': transport error ' . $e::class, 0, $e);
        }
        $data = json_decode((string) $response->getBody(), true);
        if ($response->getStatusCode() >= 400 || !is_array($data)) {
            $reason = is_array($data) && is_string($data['error'] ?? null) ? $data['error'] : 'bad reply';

            throw new OAuthException(sprintf('%s: HTTP %d (%s)', $this->id(), $response->getStatusCode(), $reason));
        }

        return $data;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    protected static function str(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : (is_int($value) ? (string) $value : null);
    }
}
