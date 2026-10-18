<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

/** Read-only transient error carrying successfully fetched counters for durable retry progress. */
final class PartialMetricsFailure extends \RuntimeException
{
    public function __construct(public readonly PostMetrics $metrics)
    {
        parent::__construct('Some statistics are temporarily unavailable.');
    }
}
