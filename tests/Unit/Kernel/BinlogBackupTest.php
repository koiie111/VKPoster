<?php

declare(strict_types=1);

namespace App\Tests\Unit\Kernel;

use App\Kernel\Operations\BackupArchive;
use App\Kernel\Operations\BinlogBackup;
use App\Tests\Support\TestEnv;
use PHPUnit\Framework\TestCase;

/** Closed-log archival is immutable, authenticated and excludes the still-active highest log. */
final class BinlogBackupTest extends TestCase
{
    public function testClosedLogsRoundtripAndRepeatWithoutDuplicates(): void
    {
        $root = sys_get_temp_dir() . '/binlog-fixture-' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        mkdir($root . '/source', 0700);
        mkdir($root . '/backup', 0700);
        mkdir($root . '/restore', 0700);
        $key = $root . '/key';
        file_put_contents($key, base64_encode(random_bytes(32)));
        chmod($key, 0600);
        $config = TestEnv::config(['BACKUP_BINLOG_ENABLED' => 'true', 'BACKUP_BINLOG_DIRECTORY' => $root . '/source', 'BACKUP_KEY_FILE' => $key]);
        $service = new BinlogBackup($config, new BackupArchive());
        try {
            file_put_contents($root . '/source/binlog.000001', "\xfebin" . random_bytes(10000));
            file_put_contents($root . '/source/binlog.000002', "\xfebin" . 'active log');
            self::assertSame(1, $service->backup($root . '/backup', false));
            $service->backup($root . '/backup', false);
            $files = \App\Support\Fs::glob($root . '/backup/*.ezb');
            self::assertCount(1, $files);
            $output = $root . '/restore/binlog.000001';
            $service->decrypt($files[0], $output);
            self::assertSame(hash_file('sha256', $root . '/source/binlog.000001'), hash_file('sha256', $output));
            self::assertFileExists($root . '/source/binlog.000002');
            self::assertSame(0600, fileperms($output) & 0777);
            // A new active log makes the previously active log eligible, preserving the first archive.
            file_put_contents($root . '/source/binlog.000003', "\xfebin" . 'next active');
            self::assertSame(2, $service->backup($root . '/backup', false));
            self::assertCount(2, \App\Support\Fs::glob($root . '/backup/*.ezb'));
            // Reproduce the old writer's SIGKILL residue. Rebuild only a proven truncated archive.
            $bytes = (string) file_get_contents($files[0]);
            file_put_contents($files[0], substr($bytes, 0, -1));
            self::assertSame(2, $service->backup($root . '/backup', false));
            self::assertCount(1, \App\Support\Fs::glob($files[0] . '.corrupt-*'));
            self::assertCount(2, \App\Support\Fs::glob($root . '/backup/*.ezb'));
            $bytes = (string) file_get_contents($files[0]);
            $bytes[100] = chr(ord($bytes[100]) ^ 1);
            file_put_contents($files[0], $bytes);
            try {
                $service->backup($root . '/backup', false);
                self::fail('Authenticated corruption must remain visible.');
            } catch (\RuntimeException $error) {
                self::assertSame('Backup authentication failed.', $error->getMessage());
            }
            unlink($output);
            rename($files[0], $files[0] . '.moved.ezb');
            $this->expectExceptionMessage('Invalid binlog archive');
            $service->decrypt($files[0] . '.moved.ezb', $output);
        } finally {
            foreach (['source', 'backup', 'restore'] as $directory) {
                foreach (\App\Support\Fs::glob($root . '/' . $directory . '/*') as $file) {
                    unlink($file);
                }
                rmdir($root . '/' . $directory);
            }
            unlink($key);
            rmdir($root);
        }
    }

    public function testDisabledBinlogBackupFailsClosed(): void
    {
        $this->expectExceptionMessage('not configured');
        (new BinlogBackup(TestEnv::config(), new BackupArchive()))->backup('/not-a-backup', false);
    }
}
