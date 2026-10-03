<?php

declare(strict_types=1);

namespace App\Tests\Feature\Kernel;

use App\Kernel\Console\Command\KeyGenerateCommand;
use App\Kernel\Console\Command\MigrateCommand;
use App\Kernel\Console\Command\MigrateFreshCommand;
use App\Kernel\Console\Command\MigrateRollbackCommand;
use App\Kernel\Console\Command\MigrateStatusCommand;
use App\Kernel\Console\Command\QueueWorkCommand;
use App\Kernel\Console\Command\ScheduleRunCommand;
use App\Kernel\Console\Command\SeedCommand;
use App\Kernel\Console\Console;
use App\Kernel\Console\Output;
use App\Kernel\Database\Migrator;
use App\Tests\Support\TestEnv;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * bin/console dispatcher and commands (destructive ones are only exercised through their refusals).
 */
#[CoversClass(Console::class)]
#[CoversClass(MigrateCommand::class)]
#[CoversClass(MigrateRollbackCommand::class)]
#[CoversClass(MigrateStatusCommand::class)]
#[CoversClass(MigrateFreshCommand::class)]
#[CoversClass(SeedCommand::class)]
#[CoversClass(QueueWorkCommand::class)]
#[CoversClass(ScheduleRunCommand::class)]
#[CoversClass(KeyGenerateCommand::class)]
final class ConsoleTest extends TestCase
{
    private const COMMANDS = [
        MigrateCommand::class,
        MigrateRollbackCommand::class,
        MigrateStatusCommand::class,
        MigrateFreshCommand::class,
        SeedCommand::class,
        QueueWorkCommand::class,
        ScheduleRunCommand::class,
        KeyGenerateCommand::class,
    ];

    /**
     * @param list<string> $argv
     * @param array<string, string> $env
     * @return array{int, string}
     */
    private function runConsole(array $argv, array $env = []): array
    {
        $app = TestEnv::app($env);
        $out = new Output();
        $code = (new Console($app->container(), self::COMMANDS))->run(['bin/console', ...$argv], $out);

        return [$code, $out->contents()];
    }

    public function testListShowsAllCommands(): void
    {
        [$code, $text] = $this->runConsole(['list']);

        self::assertSame(0, $code);
        foreach (['migrate', 'migrate:rollback', 'migrate:status', 'migrate:fresh', 'seed', 'queue:work', 'schedule:run', 'key:generate'] as $name) {
            self::assertStringContainsString($name, $text);
        }
    }

    public function testNoArgumentsListsCommands(): void
    {
        [$code, $text] = $this->runConsole([]);

        self::assertSame(0, $code);
        self::assertStringContainsString('Available commands', $text);
    }

    public function testUnknownCommandFails(): void
    {
        [$code, $text] = $this->runConsole(['nope']);

        self::assertSame(1, $code);
        self::assertStringContainsString('Unknown command', $text);
    }

    public function testMigrateIsIdempotentAndStatusShowsApplied(): void
    {
        [$code, $text] = $this->runConsole(['migrate']);
        self::assertSame(0, $code);
        self::assertStringContainsString('Nothing to migrate', $text);

        [, $status] = $this->runConsole(['migrate:status']);
        self::assertStringContainsString('applied  2026_10_04_000001_create_jobs', $status);
    }

    public function testRollbackAndMigrateRestoreSchema(): void
    {
        [, $down] = $this->runConsole(['migrate:rollback']);
        self::assertStringContainsString('rolled back:', $down);
        [, $up] = $this->runConsole(['migrate']);
        self::assertStringContainsString('migrated:', $up);
    }

    public function testFreshAndSeedAreRefusedInProduction(): void
    {
        [$fresh, $freshText] = $this->runConsole(['migrate:fresh'], ['APP_ENV' => 'production']);
        [$seed, $seedText] = $this->runConsole(['seed'], ['APP_ENV' => 'production']);

        self::assertSame(1, $fresh);
        self::assertStringContainsString('disabled', $freshText);
        self::assertSame(1, $seed);
        self::assertStringContainsString('disabled', $seedText);
        $tables = TestEnv::connection()->select("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'jobs'");
        self::assertNotSame([], $tables, 'refusal must leave the schema intact');
    }

    public function testSeedWithoutSeedersIsHarmless(): void
    {
        [$code, $text] = $this->runConsole(['seed']);

        self::assertSame(0, $code);
        self::assertStringContainsString('No seeders', $text);
    }

    public function testKeyGenerateProducesValidKey(): void
    {
        [$code, $text] = $this->runConsole(['key:generate']);

        self::assertSame(0, $code);
        self::assertSame(32, strlen((string) base64_decode(trim($text), true)));
    }

    public function testScheduleRunOnceExits(): void
    {
        [$code] = $this->runConsole(['schedule:run', '--once']);

        self::assertSame(0, $code);
    }

    public function testQueueWorkStopsAfterMaxJobsFlagWithEmptyQueue(): void
    {
        $app = TestEnv::app();
        $app->container()->get(Migrator::class);
        $worker = $app->container()->get(\App\Kernel\Queue\Worker::class);
        $worker->stop();
        $out = new Output();

        $code = (new QueueWorkCommand($worker))->run(['--queue=default', '--sleep=1', '--max-jobs=1'], $out);

        self::assertSame(0, $code);
        self::assertStringContainsString('Worker stopped', $out->contents());
    }
}
