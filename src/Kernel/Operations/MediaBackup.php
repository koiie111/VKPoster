<?php

declare(strict_types=1);

namespace App\Kernel\Operations;

use App\Kernel\Config;
use App\Support\Clock;
use RuntimeException;

/**
 * Incremental encrypted offsite copy of the local media library.
 *
 * Only new or changed files are encrypted (same key and format as SQL archives) and uploaded under `media/` in the
 * versioned backup bucket, so the bucket holds one copy of the library, not one per day. Removed files and old
 * versions leave the bucket after `BACKUP_RETENTION_DAYS`. Library files that live in S3 already are protected by that
 * bucket's versioning (`ops:backup check-media`), so this is a no-op for `MEDIA_DISK=s3`.
 */
final class MediaBackup
{
    private const PREFIX = 'media/';

    public function __construct(
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly BackupArchive $archive,
        private readonly string $root,
        private readonly ?BackupDestination $destination = null,
    ) {
    }

    /** @return array{uploaded: int, unchanged: int, pruned: int} */
    public function backup(string $directory): array
    {
        if ($this->config->string('media.disk') !== 'local') {
            return ['uploaded' => 0, 'unchanged' => 0, 'pruned' => 0];
        }
        $this->privateDirectory($directory);
        if (!is_dir($this->root) || is_link($this->root)) {
            throw new RuntimeException('Media root is not readable for backup.');
        }
        $lock = fopen($directory . '/media-backup.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Media backup already running.');
        }
        chmod($directory . '/media-backup.lock', 0600);
        try {
            return $this->upload($directory);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array{uploaded: int, unchanged: int, pruned: int} */
    private function upload(string $directory): array
    {
        $key = $this->archive->loadKey($this->config->env()->required('BACKUP_KEY_FILE'));
        $destination = $this->destination ?? new BackupDestination($this->config);
        $destination->assertVersioned();
        $local = $this->localFiles();
        $remote = $destination->listObjects(self::PREFIX);
        $uploaded = 0;
        foreach ($local as $relative => $file) {
            $object = self::PREFIX . $relative . '.ezb';
            $mtime = (int) filemtime($file);
            if (isset($remote[$object]) && $remote[$object] >= $mtime) {
                continue;
            }
            $encrypted = $directory . '/media-' . bin2hex(random_bytes(12)) . '.ezb';
            try {
                $this->archive->transform($file, $encrypted, $key);
                $destination->putMedia($object, $encrypted);
            } finally {
                foreach ([$encrypted, $encrypted . '.lock'] as $temporary) {
                    if (is_file($temporary) && !is_link($temporary)) {
                        unlink($temporary);
                    }
                }
            }
            ++$uploaded;
        }

        return ['uploaded' => $uploaded, 'unchanged' => count($local) - $uploaded, 'pruned' => $this->prune($destination, $local)];
    }

    /**
     * Decrypt the latest offsite copy of every media file into a new empty directory, never into the live library.
     *
     * @return int number of restored files
     */
    public function restore(string $directory, string $target): int
    {
        $this->privateDirectory($directory);
        if (!is_dir($target) && !mkdir($target, 0700, true)) {
            throw new RuntimeException('Media restore target cannot be created.');
        }
        $resolved = realpath($target);
        $live = realpath($this->root);
        if ($resolved === false || is_link($target) || (fileperms($target) & 0077) !== 0 || \App\Support\Fs::glob($target . '/*') !== []) {
            throw new RuntimeException('Media restore target must be an empty private directory.');
        }
        if ($live !== false && ($resolved === $live || str_starts_with($resolved . '/', $live . '/') || str_starts_with($live . '/', $resolved . '/'))) {
            throw new RuntimeException('Media restore must not overlap the live media library.');
        }
        $key = $this->archive->loadKey($this->config->env()->required('BACKUP_KEY_FILE'));
        $destination = $this->destination ?? new BackupDestination($this->config);
        $restored = 0;
        foreach (array_keys($destination->listObjects(self::PREFIX)) as $object) {
            $relative = substr($object, strlen(self::PREFIX), -strlen('.ezb'));
            if (!str_ends_with($object, '.ezb') || !$this->safeRelative($relative)) {
                throw new RuntimeException('Unexpected object name in the media backup.');
            }
            $download = $directory . '/media-restore-' . bin2hex(random_bytes(12)) . '.ezb';
            $output = $resolved . '/' . $relative;
            try {
                $hash = $destination->download($object, $download);
                if ($hash !== '' && !hash_equals($hash, (string) hash_file('sha256', $download))) {
                    throw new RuntimeException('Media backup object failed its integrity check.');
                }
                $folder = dirname($output);
                if (!is_dir($folder) && !mkdir($folder, 0700, true)) {
                    throw new RuntimeException('Media restore directory cannot be created.');
                }
                $this->archive->transform($download, $output, $key, true);
            } finally {
                foreach ([$download, $output . '.lock'] as $temporary) {
                    if (is_file($temporary) && !is_link($temporary)) {
                        unlink($temporary);
                    }
                }
            }
            ++$restored;
        }

        return $restored;
    }

    /**
     * Older than the retention window: versions superseded by a newer upload, and everything for files that are gone.
     * An empty library never prunes, so an unmounted or wiped volume cannot erase the offsite copy.
     *
     * @param array<string, string> $local
     */
    private function prune(BackupDestination $destination, array $local): int
    {
        if ($local === []) {
            return 0;
        }
        $days = max(7, $this->config->env()->int('BACKUP_RETENTION_DAYS', 30));
        $cutoff = $this->clock->now()->modify('-' . $days . ' days')->getTimestamp();
        $stale = [];
        foreach ($destination->versions(self::PREFIX) as $version) {
            $relative = substr($version['key'], strlen(self::PREFIX), -strlen('.ezb'));
            if ($version['modified'] < $cutoff && (!$version['latest'] || !isset($local[$relative]))) {
                $stale[] = $version;
            }
        }

        return $destination->deleteVersions(self::PREFIX, $stale);
    }

    /** @return array<string, string> relative path => absolute path */
    private function localFiles(): array
    {
        $root = rtrim($this->root, '/');
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo || $item->isLink() || !$item->isFile()) {
                continue;
            }
            $relative = substr($item->getPathname(), strlen($root) + 1);
            if (!$this->safeRelative($relative)) {
                throw new RuntimeException('Unexpected file name in the media library.');
            }
            $files[$relative] = $item->getPathname();
        }
        ksort($files);

        return $files;
    }

    private function safeRelative(string $relative): bool
    {
        return preg_match('~^[A-Za-z0-9._/-]{1,512}$~D', $relative) === 1 && !str_contains($relative, '..') && !str_starts_with($relative, '/');
    }

    private function privateDirectory(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory) || (fileperms($directory) & 0077) !== 0) {
            throw new RuntimeException('Backup directory must exist with mode 0700.');
        }
    }
}
