<?php

declare(strict_types=1);

namespace App\Tests\Unit\Kernel;

use App\Tests\Support\TestEnv;
use PHPUnit\Framework\TestCase;

/**
 * The self-hosted font must stay the pinned Inter release (supply-chain guard, no CDN fonts).
 */
final class FontAssetsTest extends TestCase
{
    public function testInterMatchesPinnedChecksum(): void
    {
        $file = TestEnv::basePath() . '/public/assets/fonts/InterVariable.woff2';

        self::assertFileExists($file);
        self::assertSame('8af7bd5b545567adffb3dfceb5bedb353a522d7bf1b3a2b8af7b6064156babc0', hash_file('sha256', $file));
        self::assertFileExists(TestEnv::basePath() . '/public/assets/fonts/OFL.txt');
    }
}
