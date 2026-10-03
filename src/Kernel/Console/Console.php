<?php

declare(strict_types=1);

namespace App\Kernel\Console;

use App\Kernel\Container;
use Throwable;

/**
 * Tiny CLI dispatcher behind `bin/console`: finds a command by name and runs it.
 */
final class Console
{
    /** @var array<string, Command> */
    private array $commands = [];

    /**
     * @param list<class-string<Command>> $commandClasses
     */
    public function __construct(private readonly Container $container, array $commandClasses)
    {
        foreach ($commandClasses as $class) {
            $command = $container->get($class);
            $this->commands[$command->name()] = $command;
        }
        ksort($this->commands);
    }

    /**
     * @param list<string> $argv full argv (script name first)
     */
    public function run(array $argv, Output $out): int
    {
        $name = $argv[1] ?? 'list';
        if ($name === 'list' || $name === '--help' || $name === 'help') {
            $out->line('Available commands:');
            foreach ($this->commands as $command) {
                $out->line(sprintf('  %-20s %s', $command->name(), $command->description()));
            }

            return 0;
        }
        $command = $this->commands[$name] ?? null;
        if ($command === null) {
            $out->error(sprintf('Unknown command "%s". Run `bin/console list`.', $name));

            return 1;
        }
        try {
            return $command->run(array_slice($argv, 2), $out);
        } catch (Throwable $e) {
            $out->error(sprintf('%s: %s', $e::class, $e->getMessage()));

            return 1;
        }
    }
}
