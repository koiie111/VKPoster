<?php

declare(strict_types=1);

namespace App\Integrations\Social\Telegram;

use App\Kernel\HttpClient\HttpClientInterface;
use SensitiveParameter;

/**
 * Makes a `TelegramClient` for a token. Clients are cheap and hold no connection, so there is no cache: one per call keeps
 * tokens of different workspaces from ever sharing an object.
 */
final class TelegramClientFactory
{
    public function __construct(private readonly HttpClientInterface $http, private readonly string $apiBase = TelegramClient::API_BASE)
    {
    }

    public function make(#[SensitiveParameter] string $token): TelegramClient
    {
        return new TelegramClient($this->http, $token, $this->apiBase);
    }
}
