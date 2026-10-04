<?php

declare(strict_types=1);

namespace App\Integrations\Social\Max;

use App\Kernel\HttpClient\HttpClientInterface;
use SensitiveParameter;

/**
 * Makes a `MaxClient` for a token. Clients hold no connection, so there is no cache: one per call keeps the tokens of
 * different workspaces from ever sharing an object.
 */
final class MaxClientFactory
{
    public function __construct(private readonly HttpClientInterface $http, private readonly string $apiBase = MaxClient::API_BASE)
    {
    }

    public function make(#[SensitiveParameter] string $token): MaxClient
    {
        return new MaxClient($this->http, $token, $this->apiBase);
    }
}
