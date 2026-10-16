<?php

declare(strict_types=1);

namespace App\Integrations\Social\Fake;

use App\Domain\Analytics\PostMetrics;
use App\Domain\Channel\Channel;
use App\Integrations\Social\Contracts\Credential;
use App\Integrations\Social\Contracts\StatsFetcher;

/** Explicit fake network only; fixtures never supply real-platform production data. */
final class FakeStatsFetcher implements StatsFetcher
{
    public PostMetrics $metrics;
    public ?int $subscribers = 100;
    public bool $fail = false;
    public int $calls = 0;
    public function __construct()
    {
        $this->metrics = new PostMetrics(100, 80, 8, 5, 2, 1);
    }
    public function post(Channel $channel, Credential $credential, string $externalId): PostMetrics
    {
        ++$this->calls;
        if ($this->fail) {
            throw new \RuntimeException('Statistics temporarily unavailable.');
        }
        return $this->metrics;
    }
    public function followers(Channel $channel, Credential $credential): ?int
    {
        if ($this->fail) {
            throw new \RuntimeException('Statistics temporarily unavailable.');
        }
        return $this->subscribers;
    }
}
