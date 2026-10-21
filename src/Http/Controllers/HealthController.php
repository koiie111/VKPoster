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
        return Response::json(['status' => 'ok']);
    }

    public function ready(): Response
    {
        $result = $this->health->ready(count(\App\Support\Fs::glob(dirname(__DIR__, 3) . '/database/migrations/*.php')));

        return Response::json($result, in_array('fail', $result, true) ? 503 : 200);
    }
}
