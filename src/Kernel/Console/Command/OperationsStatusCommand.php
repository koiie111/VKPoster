<?php

declare(strict_types=1);

namespace App\Kernel\Console\Command;

use App\Kernel\Console\Command;
use App\Kernel\Console\Output;
use App\Kernel\Operations\OperationalMonitor;

/** Private CLI metrics reuse the admin read models; never exposes a public diagnostics route. */
final class OperationsStatusCommand implements Command
{
    public function __construct(private readonly OperationalMonitor $monitor)
    {
    }
    public function name(): string
    {
        return 'ops:status';
    }
    public function description(): string
    {
        return 'Queue/worker/payment/platform operational health (no content or credentials)';
    }
    public function run(array $args, Output $out): int
    {
        $out->line(json_encode($this->monitor->report(), JSON_THROW_ON_ERROR));

        return 0;
    }
}
