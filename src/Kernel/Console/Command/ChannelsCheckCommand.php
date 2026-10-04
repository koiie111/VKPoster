<?php

declare(strict_types=1);

namespace App\Kernel\Console\Command;

use App\Domain\Channel\ChannelHealthService;
use App\Kernel\Console\Command;
use App\Kernel\Console\Output;

/**
 * `channels:check`: queue a health check for every channel that is due (the scheduler does this hourly).
 */
final class ChannelsCheckCommand implements Command
{
    public function __construct(private readonly ChannelHealthService $health)
    {
    }

    public function name(): string
    {
        return 'channels:check';
    }

    public function description(): string
    {
        return 'Queue health checks for channels that were not checked recently';
    }

    public function run(array $args, Output $out): int
    {
        $out->line(sprintf('Queued %d channel check(s).', $this->health->enqueueDue()));

        return 0;
    }
}
