<?php

declare(strict_types=1);

namespace App\Kernel\Operations;

use App\Kernel\Config;
use App\Support\Fs;
use RuntimeException;

/** Archives immutable closed MySQL binlogs; never modifies/purges the source or copies the active log. */
final class BinlogBackup
{
    public function __construct(private readonly Config $config, private readonly BackupArchive $archive)
    {
    }

    /**
     * @return int Number of closed logs verified and delivered; a missing/unsafe input fails closed.
     * @phpstan-impure Reads mutable filesystem/remote state and writes encrypted archives.
     */
    public function backup(string $directory, bool $offsite = true): int
    {
        $env = $this->config->env();
        if (!$env->bool('BACKUP_BINLOG_ENABLED')) {
            throw new RuntimeException('Binlog backup is not configured.');
        }
        if (!is_dir($directory) || is_link($directory) || (fileperms($directory) & 0077) !== 0) {
            throw new RuntimeException('Binlog backup directory must be private (0700).');
        }
        $lock = fopen($directory . '/binlog-archive.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Binlog archive lock unavailable.');
        }
        chmod($directory . '/binlog-archive.lock', 0600);
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Binlog archival already running.');
            }
            return $this->archiveLogs($directory, $offsite);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function archiveLogs(string $directory, bool $offsite): int
    {
        $env = $this->config->env();
        $source = $env->required('BACKUP_BINLOG_DIRECTORY');
        if (!is_dir($source) || is_link($source)) {
            throw new RuntimeException('Binlog source must be a regular read-only directory.');
        }
        if ($env->bool('BACKUP_BINLOG_ROTATE')) {
            $user = $env->required('BACKUP_DB_USERNAME');
            if ($user === $this->config->string('database.username')) {
                throw new RuntimeException('Binlog rotation requires the separate backup account.');
            }
            $dsn = $env->has('BACKUP_DB_SOCKET') ? sprintf('mysql:unix_socket=%s;dbname=%s', $env->required('BACKUP_DB_SOCKET'), $this->config->string('database.database')) : sprintf(
                'mysql:host=%s;port=%d;dbname=%s',
                $env->string('BACKUP_DB_HOST', $this->config->string('database.host')),
                $env->int('BACKUP_DB_PORT', $this->config->int('database.port')),
                $this->config->string('database.database')
            );
            $connection = new \PDO($dsn, $user, $env->required('BACKUP_DB_PASSWORD'), OperationsDbPolicy::pdoOptions($this->config, $env->string('BACKUP_DB_SOCKET')));
            OperationsDbPolicy::assertTransport($connection, $this->config, $env->string('BACKUP_DB_SOCKET'));
            $rotate = $connection->prepare('FLUSH BINARY LOGS');
            if ($rotate === false || !$rotate->execute()) {
                throw new RuntimeException('Binlog rotation failed.');
            }
        }
        $files = array_values(array_filter(Fs::glob($source . '/binlog.*'), static fn (string $file): bool => preg_match('/^binlog\.\d{6}$/D', basename($file)) === 1));
        sort($files, SORT_STRING);
        if ($files === []) {
            throw new RuntimeException('No MySQL binlogs found; verify server logging and mount.');
        }
        // The highest numbered log can still be written. A later rotation does not mutate earlier files.
        array_pop($files);
        $key = $this->archive->loadKey($env->required('BACKUP_KEY_FILE'));
        foreach ($files as $file) {
            if (!is_file($file) || is_link($file) || file_get_contents($file, false, null, 0, 4) !== "\xfebin") {
                throw new RuntimeException('Invalid MySQL binlog input.');
            }
            $hash = hash_file('sha256', $file);
            if (!is_string($hash)) {
                throw new RuntimeException('Cannot hash closed binlog.');
            }
            $encrypted = $directory . '/binlog-' . basename($file) . '-' . $hash . '.ezb';
            if (!file_exists($encrypted)) {
                $this->archive->transform($file, $encrypted, $key);
            }
            $plain = $directory . '/verify-' . bin2hex(random_bytes(12));
            try {
                try {
                    $this->archive->transform($encrypted, $plain, $key, true);
                } catch (RuntimeException $error) {
                    if ($error->getMessage() !== 'Truncated backup.') {
                        throw $error; // Authentication failures remain visible; do not conceal wrong keys/corruption.
                    }
                    // Legacy pre-atomic SIGKILL residue: preserve evidence, rebuild from the still-available closed log.
                    rename($encrypted, $encrypted . '.corrupt-' . bin2hex(random_bytes(8)));
                    $this->archive->transform($file, $encrypted, $key);
                    $this->archive->transform($encrypted, $plain, $key, true);
                }
                if (!hash_equals($hash, (string) hash_file('sha256', $plain)) || !hash_equals($hash, (string) hash_file('sha256', $file))) {
                    throw new RuntimeException('Closed binlog changed or archive verification failed.');
                }
            } finally {
                foreach ([$plain, $plain . '.lock'] as $temporary) {
                    if (is_file($temporary) && !is_link($temporary)) {
                        unlink($temporary);
                    }
                }
            }
            if ($offsite) {
                (new BackupDestination($this->config))->upload($encrypted);
            }
        }

        return count($files);
    }

    /** Decrypt into a new private file for an isolated mysqlbinlog restore; never applies SQL. */
    public function decrypt(string $file, string $output): void
    {
        if (preg_match('/^binlog-binlog\.\d{6}-([a-f0-9]{64})\.ezb$/D', basename($file), $match) !== 1
            || !is_dir(dirname($output)) || is_link(dirname($output)) || (fileperms(dirname($output)) & 0077) !== 0) {
            throw new RuntimeException('Invalid binlog archive or non-private restore directory.');
        }
        $this->archive->transform($file, $output, $this->archive->loadKey($this->config->env()->required('BACKUP_KEY_FILE')), true);
        if (!hash_equals($match[1], (string) hash_file('sha256', $output))) {
            unlink($output);
            throw new RuntimeException('Binlog archive identity mismatch.');
        }
    }
}
