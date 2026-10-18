<?php

declare(strict_types=1);

namespace App\Integrations\Payments;

use App\Integrations\Payments\Contracts\GatewayException;
use App\Kernel\HttpClient\HttpClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * The one way the newer gateways talk to a provider: a timeout, no exceptions for 4xx, a decoded JSON body. Transport trouble, HTTP 5xx and
 * 429 become a retryable `GatewayException`; a refused key becomes one with a calm message for the customer. Other 4xx answers are returned to
 * the gateway, which knows what its provider means by them.
 */
final class GatewayHttp
{
    public function __construct(private readonly HttpClientInterface $http, private readonly string $provider)
    {
    }

    /**
     * @param array<string, mixed> $options Guzzle options (`json`, `form_params`, `body`, `headers`, `auth`)
     * @return array{0: int, 1: array<string, mixed>} the HTTP status and the decoded JSON body (empty when it is not JSON)
     * @throws GatewayException
     */
    public function send(string $method, string $url, array $options = []): array
    {
        $options += ['timeout' => 20, 'connect_timeout' => 10, 'http_errors' => false];
        try {
            $response = $this->http->request($method, $url, $options);
        } catch (GuzzleException) {
            throw new GatewayException($this->provider . ': transport error.', retryable: true);
        }
        $status = $response->getStatusCode();
        $data = json_decode((string) $response->getBody(), true);
        $data = is_array($data) ? $data : [];
        if ($status >= 500 || $status === 429) {
            throw new GatewayException(sprintf('%s: HTTP %d.', $this->provider, $status), retryable: true);
        }
        if ($status === 401 || $status === 403) {
            throw new GatewayException(sprintf('%s refused the credentials (HTTP %d).', $this->provider, $status), 'Приём платежей сейчас настраивается. Попробуйте позже или напишите нам.');
        }

        return [$status, $data];
    }
}
