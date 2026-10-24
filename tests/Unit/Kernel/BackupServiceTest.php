<?php

declare(strict_types=1);

namespace App\Tests\Unit\Kernel;

use App\Kernel\Operations\BackupArchive;
use App\Kernel\Operations\BackupService;
use App\Support\SystemClock;
use App\Tests\Support\TestEnv;
use PHPUnit\Framework\TestCase;

/** A killed run must not leave plaintext dumps behind; the next run removes them under the lock. */
final class BackupServiceTest extends TestCase
{
    public function testNextRunRemovesStalePlaintextWorkDirectoryAndRefusesConcurrentRun(): void
    {
        $root = sys_get_temp_dir() . '/backup-stale-' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        $key = $root . '/key';
        file_put_contents($key, base64_encode(random_bytes(32)));
        chmod($key, 0600);
        $dir = $root . '/out';
        mkdir($dir, 0700);
        mkdir($dir . '/work-deadbeef', 0700);
        file_put_contents($dir . '/work-deadbeef/database.sql', 'SECRET ROWS');
        file_put_contents($dir . '/work-deadbeef/snapshot.tar', 'SECRET TAR');
        file_put_contents($dir . '/backup-keep.txt', 'not ours');
        $service = new BackupService(TestEnv::config(['BACKUP_KEY_FILE' => $key]), new SystemClock(), new BackupArchive());
        try {
            try {
                $service->backup($dir, false);
            } catch (\Throwable) {
                // The dump itself may fail in this environment; cleanup happens before it.
            }
            self::assertDirectoryDoesNotExist($dir . '/work-deadbeef');
            self::assertFileExists($dir . '/backup-keep.txt');
            self::assertSame([], \App\Support\Fs::glob($dir . '/work-*'));

            $lock = fopen($dir . '/backup.lock', 'c');
            self::assertIsResource($lock);
            flock($lock, LOCK_EX);
            mkdir($dir . '/work-live', 0700);
            try {
                $service->backup($dir, false);
                self::fail('Concurrent backup was allowed.');
            } catch (\RuntimeException $e) {
                self::assertSame('Backup already running.', $e->getMessage());
            }
            self::assertDirectoryExists($dir . '/work-live', 'a run that does not own the lock must not touch work directories');
            flock($lock, LOCK_UN);
            fclose($lock);
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }
}
