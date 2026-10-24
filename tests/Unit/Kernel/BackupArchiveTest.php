<?php

declare(strict_types=1);

namespace App\Tests\Unit\Kernel;

use App\Kernel\Operations\BackupArchive;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Archives authenticate every frame and the end marker; corrupted output is removed. */
final class BackupArchiveTest extends TestCase
{
    public function testRoundtripCorruptionAndTruncation(): void
    {
        $root = sys_get_temp_dir() . '/backup-test-' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        $archive = new BackupArchive();
        $key = random_bytes(32);
        $source = $root . '/source';
        file_put_contents($source, random_bytes(200000));
        try {
            $archive->transform($source, $root . '/encrypted', $key);
            $archive->transform($root . '/encrypted', $root . '/plain', $key, true);
            self::assertSame(hash_file('sha256', $source), hash_file('sha256', $root . '/plain'));
            foreach (['truncated', 'corrupted', 'wrong-key'] as $case) {
                $data = (string) file_get_contents($root . '/encrypted');
                if ($case === 'truncated') {
                    $data = substr($data, 0, -1);
                } elseif ($case === 'corrupted') {
                    $data[100] = chr(ord($data[100]) ^ 1);
                }
                file_put_contents($root . '/' . $case, $data);
                try {
                    $archive->transform($root . '/' . $case, $root . '/invalid', $case === 'wrong-key' ? random_bytes(32) : $key, true);
                    self::fail('Unauthenticated backup was accepted.');
                } catch (RuntimeException) {
                    self::assertFileDoesNotExist($root . '/invalid');
                }
            }
            $this->expectException(RuntimeException::class);
            $archive->transform($source, $root . '/encrypted', $key);
        } finally {
            foreach (\App\Support\Fs::glob($root . '/*') as $file) {
                unlink($file);
            }
            rmdir($root);
        }
    }
    public function testKilledWriterLeavesOnlyPartialAndConcurrentRerunPublishesOneVerifiedArchive(): void
    {
        $root = sys_get_temp_dir() . '/backup-crash-' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        $key = random_bytes(32);
        $source = $root . '/source';
        $output = $root . '/encrypted';
        // A sufficiently large input allows deterministic interruption as soon as a partial appears.
        $file = fopen($source, 'wb');
        self::assertIsResource($file);
        for ($i = 0; $i < 128; ++$i) {
            fwrite($file, str_repeat('synthetic backup bytes', 50000));
        }
        fclose($file);
        try {
            $pid = pcntl_fork();
            self::assertNotSame(-1, $pid);
            if ($pid === 0) {
                (new BackupArchive())->transform($source, $output, $key);
                exit(0);
            }
            $deadline = microtime(true) + 5;
            while (\App\Support\Fs::glob($output . '.partial-*') === [] && microtime(true) < $deadline) {
                usleep(1000);
            }
            self::assertNotEmpty(\App\Support\Fs::glob($output . '.partial-*'));
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
            self::assertTrue(pcntl_wifsignaled($status));
            self::assertFileDoesNotExist($output);
            $children = [];
            for ($i = 0; $i < 2; ++$i) {
                $pid = pcntl_fork();
                self::assertNotSame(-1, $pid);
                if ($pid === 0) {
                    try {
                        (new BackupArchive())->transform($source, $output, $key);
                        exit(0);
                    } catch (RuntimeException $error) {
                        exit($error->getMessage() === 'Backup output already exists.' ? 10 : 1);
                    }
                }
                $children[] = $pid;
            }
            $exits = [];
            foreach ($children as $child) {
                pcntl_waitpid($child, $status);
                $exits[] = pcntl_wexitstatus($status);
            }
            sort($exits);
            self::assertSame([0, 10], $exits);
            self::assertSame([], \App\Support\Fs::glob($output . '.partial-*'));
            (new BackupArchive())->transform($output, $root . '/plain', $key, true);
            self::assertSame(hash_file('sha256', $source), hash_file('sha256', $root . '/plain'));
        } finally {
            foreach (\App\Support\Fs::glob($root . '/*') as $path) {
                unlink($path);
            }
            rmdir($root);
        }
    }

}
