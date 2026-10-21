<?php

declare(strict_types=1);

namespace App\Kernel\Operations;

use App\Kernel\Config;
use App\Support\Clock;
use PharData;
use RuntimeException;

/** Offline-first encrypted logical backups; restore always targets a separate explicitly named database. */
final class BackupService
{
    public function __construct(private readonly Config $config, private readonly Clock $clock, private readonly BackupArchive $archive)
    {
    }

    public function backup(string $directory, bool $offsite = true): string
    {
        $key = $this->key();
        $this->directory($directory);
        $work = $directory . '/work-' . bin2hex(random_bytes(8));
        mkdir($work, 0700);
        $file = $directory . '/backup-' . $this->clock->now()->format('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.ezb';
        try {
            $binlogs = $this->config->env()->bool('BACKUP_BINLOG_ENABLED');
            if ($binlogs && (!$this->config->env()->has('BACKUP_DB_USERNAME') || $this->config->env()->string('BACKUP_DB_USERNAME') === $this->config->string('database.username'))) {
                throw new RuntimeException('PITR snapshot requires a separate backup database account.');
            }
            if ($binlogs) {
                $this->config->env()->required('BACKUP_DB_PASSWORD');
            }
            $this->mysqlProcess('mysqldump', [...($binlogs ? ['--source-data=2', '--flush-logs'] : []), '--single-transaction', '--no-tablespaces', '--skip-lock-tables', '--hex-blob', '--set-gtid-purged=OFF', '--routines', '--events', '--triggers', $this->config->string('database.database')], null, $work . '/database.sql');
            $metadata = ['format' => 1, 'created_at' => $this->clock->now()->format(DATE_ATOM), 'app_version' => $this->config->string('app.version'),
                'database' => $this->config->string('database.database'), 'sha256' => hash_file('sha256', $work . '/database.sql'), 'recovery' => $binlogs ? 'logical snapshot with binlog coordinates; closed logs archived separately' : 'logical snapshot only'];
            file_put_contents($work . '/manifest.json', json_encode($metadata, JSON_THROW_ON_ERROR));
            $tar = new PharData($work . '/snapshot.tar');
            $tar->addFile($work . '/database.sql', 'database.sql');
            $tar->addFile($work . '/manifest.json', 'manifest.json');
            unset($tar);
            $this->archive->transform($work . '/snapshot.tar', $file, $key);
            $this->verify($file);
            if ($offsite) {
                (new BackupDestination($this->config))->upload($file);
            }
            // Delete only our encrypted snapshots, never the key, raw data, media or arbitrary files.
            $days = max(7, $this->config->env()->int('BACKUP_RETENTION_DAYS', 30));
            foreach (\App\Support\Fs::glob($directory . '/backup-*.ezb') as $old) {
                if (!is_link($old) && filemtime($old) < $this->clock->now()->modify('-' . $days . ' days')->getTimestamp()) {
                    unlink($old);
                }
            }

            return basename($file);
        } finally {
            foreach (\App\Support\Fs::glob($work . '/*') as $temporary) {
                unlink($temporary);
            }
            rmdir($work);
        }
    }

    public function verify(string $file): void
    {
        $this->unpack($file, static function (string $sql): void {
        });
    }

    public function verifyMediaVersioning(): void
    {
        (new BackupDestination($this->config))->verifyMediaVersioning();
    }

    public function restore(string $file, string $target, bool $confirmed): void
    {
        if (!$confirmed || preg_match('/^app_restore_[a-z0-9_]{1,40}$/D', $target) !== 1
            || $target === $this->config->string('database.database')) {
            throw new RuntimeException('Restore requires --confirm-isolated and a separate app_restore_* database.');
        }
        $env = $this->config->env();
        $user = $env->required('RESTORE_DB_USERNAME');
        if ($user === $this->config->string('database.username')) {
            throw new RuntimeException('Restore must use a separately provisioned restricted database account.');
        }
        $socket = $env->string('RESTORE_DB_SOCKET');
        $dsn = $socket !== '' ? sprintf('mysql:unix_socket=%s;dbname=%s', $socket, $target)
            : sprintf('mysql:host=%s;port=%d;dbname=%s', $env->string('RESTORE_DB_HOST', $this->config->string('database.host')), $env->int('RESTORE_DB_PORT', $this->config->int('database.port')), $target);
        $pdo = new \PDO($dsn, $user, $env->required('RESTORE_DB_PASSWORD'), [\PDO::ATTR_TIMEOUT => 5]);
        $grants = $pdo->query('SHOW GRANTS');
        if ($grants === false) {
            throw new RuntimeException('Cannot verify restore account privileges.');
        }
        $grantMode = $pdo->query('SELECT @@partial_revokes');
        if ($grantMode === false) {
            throw new RuntimeException('Cannot verify restore grant semantics.');
        }
        $literalGrants = (int) $grantMode->fetchColumn() === 1;
        $escapedTarget = str_replace('_', '\\_', $target);
        foreach ($grants->fetchAll(\PDO::FETCH_COLUMN) as $grant) {
            if (!is_string($grant) || (!str_starts_with($grant, 'GRANT USAGE ON *.* TO ')
                && !str_contains($grant, ' ON `' . $escapedTarget . '`.* TO ')
                && !($literalGrants && str_contains($grant, ' ON `' . $target . '`.* TO ')))) {
                throw new RuntimeException('Restore account must have privileges only on the isolated target.');
            }
        }
        $query = $pdo->query('SHOW TABLES');
        if ($query === false || $query->fetchColumn() !== false) {
            throw new RuntimeException('Restore target must be empty.');
        }
        // No DROP/CREATE DATABASE: the owner must provision an empty isolated target beforehand.
        $this->unpack($file, function (string $sql) use ($target): void {
            $this->mysqlProcess('mysql', [$target], $sql, '/dev/null');
        });
    }

    /** @param callable(string): void $callback */
    private function unpack(string $file, callable $callback): void
    {
        $work = sys_get_temp_dir() . '/ezposter-restore-' . bin2hex(random_bytes(12));
        mkdir($work, 0700);
        try {
            $this->archive->transform($file, $work . '/snapshot.tar', $this->key(), true);
            $tar = new PharData($work . '/snapshot.tar');
            if (count($tar) !== 2 || !isset($tar['database.sql'], $tar['manifest.json']) || $tar['database.sql']->isLink() || $tar['manifest.json']->isLink()) {
                throw new RuntimeException('Unexpected backup entries.');
            }
            $tar->extractTo($work, ['database.sql', 'manifest.json']);
            $manifest = json_decode((string) file_get_contents($work . '/manifest.json'), true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($manifest) || ($manifest['format'] ?? null) !== 1 || !is_string($manifest['sha256'] ?? null)
                || !hash_equals($manifest['sha256'], (string) hash_file('sha256', $work . '/database.sql'))) {
                throw new RuntimeException('Backup manifest integrity check failed.');
            }
            $callback($work . '/database.sql');
        } finally {
            foreach (\App\Support\Fs::glob($work . '/*') as $temporary) {
                unlink($temporary);
            }
            rmdir($work);
        }
    }

    private function key(): string
    {
        return $this->archive->loadKey($this->config->env()->required('BACKUP_KEY_FILE'));
    }

    private function directory(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory) || (fileperms($directory) & 0077) !== 0) {
            throw new RuntimeException('Backup directory must exist with mode 0700.');
        }
    }

    /** @param list<string> $args */
    private function mysqlProcess(string $binary, array $args, ?string $input, string $output): void
    {
        // Only version-compatible Oracle CLI is supported; no MariaDB fallback.
        $bundle = '/opt/mysql/' . $binary;
        if (!is_executable($bundle)) {
            throw new RuntimeException('Version-matched Oracle backup client bundle required.');
        }
        $binary = $bundle;
        // Credentials stay out of argv/logs; a temporary option file is mode 0600 inside private tmpfs.
        $file = tempnam(sys_get_temp_dir(), 'backup-client-');
        if ($file === false) {
            throw new RuntimeException('Backup configuration unavailable.');
        }
        chmod($file, 0600);
        $options = "[client]\n";
        foreach (['host' => 'host', 'port' => 'port', 'user' => 'username', 'password' => 'password'] as $name => $setting) {
            $value = $this->config->string('database.' . $setting);
            if ($input === null) {
                $envName = 'BACKUP_DB_' . match ($name) {
                    'host' => 'HOST', 'port' => 'PORT', 'user' => 'USERNAME', default => 'PASSWORD'
                };
                $value = $this->config->env()->string($envName, $value);
            } else {
                $envName = match ($name) {
                    'host' => 'RESTORE_DB_HOST', 'port' => 'RESTORE_DB_PORT', 'user' => 'RESTORE_DB_USERNAME', default => 'RESTORE_DB_PASSWORD'
                };
                $value = $this->config->env()->string($envName, $value);
            }
            $options .= $name . '="' . addcslashes($value, "\\\"\n\r") . "\"\n";
        }
        $socket = $this->config->env()->string($input === null ? 'BACKUP_DB_SOCKET' : 'RESTORE_DB_SOCKET');
        if ($socket !== '') {
            // Local Unix sockets are a secure transport; do not disable TLS verification on TCP.
            $options .= "host=localhost\nprotocol=SOCKET\nsocket=\"" . addcslashes($socket, "\\\"\n\r") . "\"\n";
        }
        if ($socket === '' && $this->config->isProduction()) {
            $options .= "ssl-mode=VERIFY_IDENTITY\n";
        }
        file_put_contents($file, $options);
        try {
            $process = proc_open(
                [$binary, '--defaults-file=' . $file, ...$args],
                [0 => ['file', $input ?? '/dev/null', 'r'], 1 => ['file', $output, 'w'], 2 => ['file', '/dev/null', 'w']],
                $pipes
            );
            if (!is_resource($process)) {
                throw new RuntimeException('Backup database client unavailable.');
            }
            $deadline = hrtime(true) + 300_000_000_000;
            do {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    break;
                }
                usleep(100000);
            } while (hrtime(true) < $deadline);
            if ($status['running']) {
                proc_terminate($process, 9);
            }
            $exit = proc_close($process);
            if ($status['running'] || ($status['exitcode'] !== 0 && $exit !== 0)) {
                throw new RuntimeException('Backup database operation failed or timed out.');
            }
        } finally {
            unlink($file);
        }
    }
}
