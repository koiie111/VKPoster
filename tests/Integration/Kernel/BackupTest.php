<?php

declare(strict_types=1);

namespace App\Tests\Integration\Kernel;

use App\Kernel\Database\Connection;
use App\Kernel\Operations\BackupArchive;
use App\Kernel\Operations\BackupService;
use App\Tests\Support\FakeClock;
use App\Tests\Support\TestEnv;
use PHPUnit\Framework\TestCase;

/** Actual mysqldump/encryption/restore against a separate database and restricted restore account. */
final class BackupTest extends TestCase
{
    public function testWildcardDatabaseGrantIsNotAnIsolatedRestoreAccount(): void
    {
        $config = TestEnv::config(['RESTORE_DB_USERNAME' => 'stage19_wildcard_restore', 'RESTORE_DB_PASSWORD' => 'isolated-fixture-only']);
        $service = new BackupService($config, new FakeClock(), new BackupArchive());
        $this->expectExceptionMessage('privileges only on the isolated target');
        $service->restore('/not-read-before-privilege-check.ezb', 'app_restore_stage19', true);
    }

    public function testRestoresOriginalDataOnlyIntoEmptyIsolatedTarget(): void
    {
        $root = sys_get_temp_dir() . '/backup-integration-' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        $keyFile = $root . '/key';
        file_put_contents($keyFile, base64_encode(random_bytes(32)));
        chmod($keyFile, 0600);
        $source = TestEnv::connection();
        $config = TestEnv::config(['BACKUP_KEY_FILE' => $keyFile, 'RESTORE_DB_USERNAME' => 'stage19_restore', 'RESTORE_DB_PASSWORD' => 'isolated-fixture-only']);
        $target = new Connection($config->string('database.host'), $config->int('database.port'), 'app_restore_stage19', 'stage19_restore', 'isolated-fixture-only');
        $source->execute('CREATE TABLE backup_probe (id INT PRIMARY KEY, value VARCHAR(64))');
        $source->execute('INSERT INTO backup_probe VALUES (1, ?)', ['original']);
        $service = new BackupService($config, new FakeClock('2026-10-19 00:00:00'), new BackupArchive());
        try {
            $name = $service->backup($root, false);
            self::assertFileExists($root . '/' . $name);
            $source->execute('UPDATE backup_probe SET value = ?', ['changed']);
            $service->restore($root . '/' . $name, 'app_restore_stage19', true);
            self::assertSame('original', $target->select('SELECT value FROM backup_probe')[0]['value']);
            self::assertSame('changed', $source->select('SELECT value FROM backup_probe')[0]['value']);
            $service->verify($root . '/' . $name);
            foreach (['app', 'app_test', 'app_restore_other'] as $database) {
                try {
                    $service->restore($root . '/' . $name, $database, false);
                    self::fail('Unsafe restore accepted.');
                } catch (\RuntimeException $error) {
                    self::assertStringContainsString('Restore requires', $error->getMessage());
                }
            }
            $this->expectException(\RuntimeException::class);
            $service->restore($root . '/' . $name, 'app_restore_stage19', true);
        } finally {
            $source->execute('DROP TABLE backup_probe');
            $target->execute('SET FOREIGN_KEY_CHECKS=0');
            foreach ($target->select('SHOW TABLES') as $row) {
                $table = (string) array_values($row)[0];
                if (preg_match('/^[a-z0-9_]+$/D', $table) === 1) {
                    $target->execute('DROP TABLE `' . $table . '`');
                }
            }
            $target->execute('SET FOREIGN_KEY_CHECKS=1');
            foreach (\App\Support\Fs::glob($root . '/*') as $file) {
                unlink($file);
            }
            rmdir($root);
        }
    }
}
