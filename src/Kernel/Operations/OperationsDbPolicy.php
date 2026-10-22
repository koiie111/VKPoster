<?php

declare(strict_types=1);

namespace App\Kernel\Operations;

use App\Kernel\Config;
use PDO;
use RuntimeException;

/** One transport policy for Oracle CLI and PDO operational connections; no production TCP fallback. */
final class OperationsDbPolicy
{
    public static function ca(Config $config, string $socket): string
    {
        if (!$config->isProduction() || $socket !== '') {
            return '';
        }
        $file = $config->env()->string('OPERATIONS_DB_TLS_CA_FILE');
        if ($file === '' || !is_file($file) || is_link($file) || !is_readable($file) || !str_starts_with($file, '/')) {
            throw new RuntimeException('Production operations TCP requires an explicit readable CA file.');
        }
        return $file;
    }

    /** @return array<int, mixed> */
    public static function pdoOptions(Config $config, string $socket): array
    {
        $options = [PDO::ATTR_TIMEOUT => 5, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false];
        $ca = self::ca($config, $socket);
        if ($ca !== '') {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $ca;
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
        }
        return $options;
    }

    /** Assert negotiated TLS too: no PDO/mysqlnd fallback to an unencrypted production TCP session. */
    public static function assertTransport(PDO $connection, Config $config, string $socket): void
    {
        if (self::ca($config, $socket) === '') {
            return;
        }
        $query = $connection->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'");
        $row = $query === false ? false : $query->fetch(PDO::FETCH_NUM);
        if (!is_array($row) || !is_string($row[1] ?? null) || $row[1] === '') {
            throw new RuntimeException('Production operations require a verified TLS session.');
        }
    }

    public static function cliOptions(Config $config, string $socket): string
    {
        $ca = self::ca($config, $socket);
        return $ca === '' ? '' : "ssl-mode=VERIFY_IDENTITY\nssl-ca=\"" . addcslashes($ca, "\\\"\n\r") . "\"\n";
    }
}
