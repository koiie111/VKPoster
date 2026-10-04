<?php

declare(strict_types=1);

namespace App\Tests\Feature\Post;

use App\Kernel\Console\Command\BenchPublishCommand;
use App\Kernel\Console\Output;
use App\Tests\Support\TestEnv;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BenchPublishCommand::class)]
final class BenchCommandTest extends TestCase
{
    public function testItNeedsTheFakeFlagAndRefusesProduction(): void
    {
        $app = TestEnv::app();
        $command = new BenchPublishCommand($app->container(), TestEnv::config());
        $out = new Output();

        self::assertSame(1, $command->run([], $out));
        self::assertStringContainsString('--fake', $out->contents());

        $production = TestEnv::config(['APP_ENV' => 'production', 'APP_URL' => 'https://example.com', 'DEV_LOGIN' => '0', 'PLATFORMS_ENABLED' => 'telegram']);
        $refused = new Output();
        self::assertSame(1, (new BenchPublishCommand($app->container(), $production))->run(['--fake'], $refused));
        self::assertStringContainsString('production', $refused->contents());
    }
}
