<?php

declare(strict_types=1);

namespace App\Integrations\Social\Contracts;

use App\Domain\Channel\Channel;
use App\Domain\Analytics\PostMetrics;

/** Read-only metrics; unsupported counters are null, never fabricated zeroes. */
interface StatsFetcher
{
    public function post(Channel $channel, Credential $credential, string $externalId): PostMetrics;
    public function followers(Channel $channel, Credential $credential): ?int;
}
