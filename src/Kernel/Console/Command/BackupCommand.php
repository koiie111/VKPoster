<?php

declare(strict_types=1);

namespace App\Kernel\Console\Command;

use App\Kernel\Console\Command;
use App\Kernel\Console\Output;
use App\Kernel\Operations\BackupService;
use App\Kernel\Operations\BinlogBackup;

/** Explicit operational entrypoint; restore cannot target the current application database. */
final class BackupCommand implements Command
{
    public function __construct(private readonly BackupService $backup, private readonly BinlogBackup $binlogs)
    {
    }

    public function name(): string
    {
        return 'ops:backup';
    }

    public function description(): string
    {
        return 'backup|binlogs DIRECTORY [--local-only] | check-media | decrypt-binlog ARCHIVE OUTPUT | verify ARCHIVE | restore ARCHIVE app_restore_NAME --confirm-isolated';
    }

    public function run(array $args, Output $out): int
    {
        match ($args[0] ?? '') {
            'backup' => $out->line($this->backup->backup($args[1] ?? '', !in_array('--local-only', $args, true))),
            'binlogs' => $out->line((string) $this->binlogs->backup($args[1] ?? '', !in_array('--local-only', $args, true))),
            'check-media' => $this->backup->verifyMediaVersioning(),
            'decrypt-binlog' => $this->binlogs->decrypt($args[1] ?? '', $args[2] ?? ''),
            'verify' => $this->backup->verify($args[1] ?? ''),
            'restore' => $this->backup->restore($args[1] ?? '', $args[2] ?? '', in_array('--confirm-isolated', $args, true)),
            default => throw new \InvalidArgumentException($this->description()),
        };

        return 0;
    }
}
