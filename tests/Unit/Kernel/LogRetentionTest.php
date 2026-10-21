<?php

declare(strict_types=1);

namespace App\Tests\Unit\Kernel;

use App\Kernel\Operations\LogRetention;
use App\Tests\Support\FakeClock;
use PHPUnit\Framework\TestCase;

/** Age-based log pruning must not touch current logs, other files or symlink targets. */
final class LogRetentionTest extends TestCase
{
    public function testThirtyDayCutoffAndSymlinkSafety(): void
    {
        $root = sys_get_temp_dir() . '/retention-' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        foreach (['app-2026-08-01.log', 'app-2026-10-19.log', 'source.media'] as $file) {
            file_put_contents($root . '/' . $file, 'fixture');
        }
        symlink($root . '/source.media', $root . '/app-2026-07-01.log');
        try {
            $service = new LogRetention(new FakeClock('2026-10-19 00:00:00'));
            self::assertSame(1, $service->prune($root));
            self::assertFileExists($root . '/app-2026-10-19.log');
            self::assertFileExists($root . '/source.media');
            self::assertTrue(is_link($root . '/app-2026-07-01.log'));
        } finally {
            foreach (\App\Support\Fs::glob($root . '/*') as $file) {
                unlink($file);
            }
            rmdir($root);
        }
    }
}
