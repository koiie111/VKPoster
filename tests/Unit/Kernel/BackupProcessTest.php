<?php

declare(strict_types=1);

namespace App\Tests\Unit\Kernel;

use App\Kernel\Operations\BackupProcess;
use App\Tests\Support\TestEnv;
use PHPUnit\Framework\TestCase;

/** Monotonic process deadline regressions without waiting five minutes; real children are always reaped. */
final class BackupProcessTest extends TestCase
{
    public function testTimeoutConfigDefaultAndOverride(): void
    {
        self::assertSame(1800, TestEnv::config()->int('operations.backup_process_timeout'));
        self::assertSame(3600, TestEnv::config(['BACKUP_PROCESS_TIMEOUT' => '3600'])->int('operations.backup_process_timeout'));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidTimeouts(): iterable
    {
        foreach (['0', '-1', '86401', '1.5', 'bad'] as $value) {
            yield $value => [$value];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidTimeouts')]
    public function testInvalidTimeoutIsRejectedAtProductionStartup(string $value): void
    {
        $this->expectException(\App\Kernel\Exception\ConfigException::class);
        $this->expectExceptionMessage('BACKUP_PROCESS_TIMEOUT');
        TestEnv::config(['APP_ENV' => 'production', 'BACKUP_PROCESS_TIMEOUT' => $value]);
    }

    public function testProcessPastOldFiveMinuteLimitCompletesWithinConfiguredDeadline(): void
    {
        $calls = 0;
        $runner = new BackupProcess(static function () use (&$calls): float {
            return $calls++ === 0 ? 0.0 : 301.0;
        });
        $timeout = TestEnv::config(['BACKUP_PROCESS_TIMEOUT' => '600'])->int('operations.backup_process_timeout');
        $runner->run([PHP_BINARY, '-r', 'usleep(20000);'], null, '/dev/null', $timeout);
        self::assertGreaterThanOrEqual(2, $calls);
    }

    public function testExceededDeadlineKillsAndReapsChildAndReportsFailure(): void
    {
        $root = sys_get_temp_dir() . '/backup-timeout-' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        $pidFile = $root . '/pid';
        $calls = 0;
        $runner = new BackupProcess(static function () use (&$calls, $pidFile): float {
            if ($calls++ === 0) {
                return 0.0;
            }
            // Synchronize with the child start, not real elapsed backup time.
            $until = microtime(true) + 2;
            while (!is_file($pidFile) && microtime(true) < $until) {
                usleep(1000);
            }
            return 601.0;
        });
        try {
            $runner->run([PHP_BINARY, '-r', 'file_put_contents($argv[1], (string) getmypid()); sleep(60);', $pidFile], null, '/dev/null', 600);
            self::fail('Timeout must never return success.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('timed out', $error->getMessage());
            self::assertStringContainsString('recreate the isolated restore target', $error->getMessage());
            self::assertFileExists($pidFile);
            $pid = (int) file_get_contents($pidFile);
            self::assertGreaterThan(0, $pid);
            self::assertFalse(posix_kill($pid, 0), 'timed out child is killed and reaped');
        } finally {
            if (is_file($pidFile)) {
                unlink($pidFile);
            }
            rmdir($root);
        }
    }

    public function testNonzeroClientExitIsNeverSuccess(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('operation failed');
        (new BackupProcess())->run([PHP_BINARY, '-r', 'exit(2);'], null, '/dev/null', 1800);
    }
}
