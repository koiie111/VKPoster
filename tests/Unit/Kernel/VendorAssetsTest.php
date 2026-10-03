<?php

declare(strict_types=1);

namespace App\Tests\Unit\Kernel;

use App\Tests\Support\TestEnv;
use PHPUnit\Framework\TestCase;

/**
 * Vendored front-end libraries must match the pinned SHA-256 sums (supply-chain guard).
 */
final class VendorAssetsTest extends TestCase
{
    public function testVendoredFilesMatchPinnedChecksums(): void
    {
        $dir = TestEnv::basePath() . '/public/assets/vendor';
        $lines = file($dir . '/SHA256SUMS', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($lines);
        self::assertNotSame([], $lines);

        foreach ($lines as $line) {
            [$expected, $name] = preg_split('/\s+/', $line, 2) + [1 => ''];
            self::assertFileExists($dir . '/' . $name);
            self::assertSame($expected, hash_file('sha256', $dir . '/' . $name), $name . ' does not match SHA256SUMS');
        }
    }
}
