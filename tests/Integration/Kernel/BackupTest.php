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
    public function testTimedOutRestoreIsExplicitFailureAndLeavesTargetReprovisionToOwner(): void
    {
        $root = sys_get_temp_dir() . '/restore-timeout-' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        $key = random_bytes(32);
        file_put_contents($root . '/key', base64_encode($key));
        chmod($root . '/key', 0600);
        $sql = 'CREATE TABLE timeout_probe (id INT PRIMARY KEY); INSERT INTO timeout_probe VALUES (1); DO SLEEP(5); INSERT INTO timeout_probe VALUES (2);';
        file_put_contents($root . '/database.sql', $sql);
        file_put_contents($root . '/manifest.json', json_encode(['format' => 1, 'sha256' => hash('sha256', $sql)], JSON_THROW_ON_ERROR));
        $tar = new \PharData($root . '/snapshot.tar');
        $tar->addFile($root . '/database.sql', 'database.sql');
        $tar->addFile($root . '/manifest.json', 'manifest.json');
        unset($tar);
        (new BackupArchive())->transform($root . '/snapshot.tar', $root . '/backup.ezb', $key);
        $config = TestEnv::config(['BACKUP_KEY_FILE' => $root . '/key', 'BACKUP_PROCESS_TIMEOUT' => '1', 'RESTORE_DB_USERNAME' => 'stage19_restore', 'RESTORE_DB_PASSWORD' => 'isolated-fixture-only']);
        $target = new Connection($config->string('database.host'), $config->int('database.port'), 'app_restore_stage19', 'stage19_restore', 'isolated-fixture-only');
        try {
            (new BackupService($config, new FakeClock(), new BackupArchive()))->restore($root . '/backup.ezb', 'app_restore_stage19', true);
            self::fail('Partial restore must not report success.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('timed out', $error->getMessage());
            self::assertStringContainsString('recreate the isolated restore target', $error->getMessage());
            self::assertSame(1, (int) $target->select('SELECT COUNT(*) AS n FROM timeout_probe')[0]['n']);
            try {
                (new BackupService($config, new FakeClock(), new BackupArchive()))->restore($root . '/backup.ezb', 'app_restore_stage19', true);
                self::fail('Retry into a partially populated target must be refused.');
            } catch (\RuntimeException $retry) {
                self::assertSame('Restore target must be empty.', $retry->getMessage());
            }
        } finally {
            $target->execute('DROP TABLE IF EXISTS timeout_probe');
            foreach (\App\Support\Fs::glob($root . '/*') as $file) {
                unlink($file);
            }
            rmdir($root);
        }
    }

}
