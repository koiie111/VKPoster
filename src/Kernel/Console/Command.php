<?php

declare(strict_types=1);

namespace App\Kernel\Console;

/**
 * A `bin/console` command. Dependencies come through the constructor (autowired).
 */
interface Command
{
    /**
     * Name typed after `bin/console`, e.g. `migrate:rollback`.
     */
    public function name(): string;

    public function description(): string;

    /**
     * @param list<string> $args arguments after the command name (`--flag`, `--key=value`, plain)
     * @return int process exit code (0 = success)
     */
    public function run(array $args, Output $out): int;
}
