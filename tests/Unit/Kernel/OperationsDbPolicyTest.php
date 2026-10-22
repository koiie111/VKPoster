<?php

declare(strict_types=1);

namespace App\Tests\Unit\Kernel;

use App\Kernel\Operations\OperationsDbPolicy;
use App\Tests\Support\TestEnv;
use PDO;
use PHPUnit\Framework\TestCase;

/** CLI/PDO share a fail-closed production TCP policy; sockets and non-production remain explicit. */
final class OperationsDbPolicyTest extends TestCase
{
    public function testProductionTcpRequiresExplicitCaForBothTransports(): void
    {
        $config = TestEnv::config(['APP_ENV' => 'production']);
        foreach ([false, true] as $cli) {
            try {
                $cli ? OperationsDbPolicy::cliOptions($config, '') : OperationsDbPolicy::pdoOptions($config, '');
                self::fail('Remote production operations without a CA must fail closed.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('explicit readable CA', $error->getMessage());
            }
        }
        self::assertArrayNotHasKey(PDO::MYSQL_ATTR_SSL_CA, OperationsDbPolicy::pdoOptions($config, '/run/mysql.sock'));
        self::assertSame('', OperationsDbPolicy::cliOptions(TestEnv::config(), ''));
    }

    public function testExplicitCaEnablesIdentityVerificationInBothDrivers(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'operations-ca-');
        self::assertIsString($file);
        file_put_contents($file, 'synthetic fixture CA (policy test only)');
        try {
            $config = TestEnv::config(['APP_ENV' => 'production', 'OPERATIONS_DB_TLS_CA_FILE' => $file]);
            $options = OperationsDbPolicy::pdoOptions($config, '');
            self::assertSame($file, $options[PDO::MYSQL_ATTR_SSL_CA]);
            self::assertTrue($options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT]);
            self::assertStringContainsString('ssl-mode=VERIFY_IDENTITY', OperationsDbPolicy::cliOptions($config, ''));
            self::assertStringContainsString($file, OperationsDbPolicy::cliOptions($config, ''));
        } finally {
            unlink($file);
        }
    }
}
