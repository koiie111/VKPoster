<?php

declare(strict_types=1);

namespace App\Integrations\Social\Instagram;

use App\Domain\Analytics\{PostMetrics,PartialMetricsFailure};
use App\Domain\Channel\Channel;
use App\Integrations\Social\Contracts\{Credential,StatsFetcher,PlatformError,ErrorKind};

/** Independent Insights reads: one unsupported media metric never discards other counters. */
final class InstagramStatsFetcher implements StatsFetcher
{
    private const METRICS = ['views' => 'views','reach' => 'reach','engagement' => 'total_interactions',
        'likes' => 'likes','comments' => 'comments','shares' => 'shares'];

    public function __construct(private readonly InstagramApi $api)
    {
    }

    public function post(Channel $channel, Credential $credential, string $externalId): PostMetrics
    {
        $values = [];
        $statuses = [];
        $retry = false;
        foreach (self::METRICS as $name => $metric) {
            $values[$name] = null;
            try {
                $response = $this->api->get('/'.$externalId.'/insights', ['metric' => $metric], $credential->secret);
                foreach ($response['data'] ?? [] as $row) {
                    if (($row['name'] ?? null) === $metric) {
                        $values[$name] = PostMetrics::counter($row['total_value']['value'] ?? $row['values'][0]['value'] ?? null);
                    }
                }
                $statuses[$name] = $values[$name] === null ? 'unavailable' : 'available';
            } catch (PlatformError $error) {
                $code = $error->platformCode ?? 0;
                $statuses[$name] = match ($error->kind) {
                    ErrorKind::Auth => $code === 10
                        || ($code >= 200 && $code <= 299)
                        ? 'permission_denied' : 'unavailable',
                    ErrorKind::Permanent => $code === 100 ? 'unsupported' : 'unavailable',
                    default => 'transient_failure',
                };
                $retry = $retry || $statuses[$name] === 'transient_failure';
            }
        }
        $result = new PostMetrics(...($values + ['statuses' => $statuses]));
        if ($retry) {
            throw new PartialMetricsFailure($result);
        }
        return $result;
    }

    public function followers(Channel $channel, Credential $credential): ?int
    {
        $r = $this->api->get('/'.$channel->externalId, ['fields' => 'followers_count'], $credential->secret);
        return PostMetrics::counter($r['followers_count'] ?? null);
    }
}
