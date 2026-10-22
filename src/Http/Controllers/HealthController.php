<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Kernel\ReadinessState;
use App\Kernel\Http\Response;

/**
 * `/healthz`: liveness probe for docker/monitoring. Reports only ok/fail per dependency.
 */
final class HealthController
{
    public function __construct(private readonly ReadinessState $health)
    {
    }

    public function show(): Response
    {
        return Response::json(['status' => 'ok']);
    }

    public function ready(): Response
    {
        $ready = $this->health->ready();
        return Response::json(['status' => $ready ? 'ok' : 'unavailable'], $ready ? 200 : 503);
    }
}
