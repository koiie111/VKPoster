<?php

declare(strict_types=1);

namespace App\Kernel\Console\Command;

use App\Domain\Auth\AuthMaintenance;
use App\Kernel\Console\Command;
use App\Kernel\Console\Output;

/**
 * `auth:prune`: delete finished tokens, old device rows and old sign-in journal entries (also runs daily from the scheduler).
 */
final class AuthPruneCommand implements Command
{
    public function __construct(private readonly AuthMaintenance $maintenance)
    {
    }

    public function name(): string
    {
        return 'auth:prune';
    }

    public function description(): string
    {
        return 'Delete expired auth tokens, old sessions and sign-in journal rows';
    }

    public function run(array $args, Output $out): int
    {
        $result = $this->maintenance->prune();
        $out->line(sprintf('Deleted: %d tokens, %d sessions, %d journal rows, %d invitations.', $result['tokens'], $result['sessions'], $result['attempts'], $result['invitations']));

        return 0;
    }
}
