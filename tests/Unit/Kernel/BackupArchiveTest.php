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
}
