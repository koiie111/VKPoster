<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Kernel\HealthCheck;
use App\Kernel\Http\Response;

/**
 * `/healthz`: liveness probe for docker/monitoring. Reports only ok/fail per dependency.
 */
final class HealthController
{
    public function __construct(private readonly HealthCheck $health)
    {
    }

    public function show(): Response
    {
        $result = $this->health->run();

        return Response::json($result, HealthCheck::isHealthy($result) ? 200 : 503);
    }
}
