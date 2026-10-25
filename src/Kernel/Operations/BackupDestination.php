<?php

declare(strict_types=1);

namespace App\Kernel\Operations;

use App\Kernel\Config;
use Aws\S3\S3Client;
use RuntimeException;

/** Uploads encrypted archives only, after verifying offsite bucket versioning. */
final class BackupDestination
{
    public function __construct(private readonly Config $config, private ?S3Client $client = null)
    {
    }

    /** Transport errors propagate: failed offsite delivery must never look like a successful backup. */
    public function upload(string $file): void
    {
        if (!is_file($file) || is_link($file) || !str_ends_with($file, '.ezb') || file_get_contents($file, false, null, 0, 10) !== "EZBACKUP1\n") {
            throw new RuntimeException('Only encrypted backup archives may be uploaded.');
        }
        $s3 = $this->s3();
        $bucket = $this->bucket();
        if ($s3->getBucketVersioning(['Bucket' => $bucket])['Status'] !== 'Enabled') {
            throw new RuntimeException('Backup bucket versioning must be enabled.');
        }
        $s3->putObject(['Bucket' => $bucket, 'Key' => 'backups/' . basename($file), 'SourceFile' => $file,
            'Metadata' => ['sha256' => (string) hash_file('sha256', $file)], 'ContentType' => 'application/octet-stream']);
    }

    /** Upload one encrypted media archive under `media/`; the caller has already checked bucket versioning. */
    public function putMedia(string $key, string $file): void
    {
        if (!is_file($file) || is_link($file) || file_get_contents($file, false, null, 0, 10) !== "EZBACKUP1\n") {
            throw new RuntimeException('Only encrypted backup archives may be uploaded.');
        }
        $this->s3()->putObject(['Bucket' => $this->bucket(), 'Key' => $key, 'SourceFile' => $file,
            'Metadata' => ['sha256' => (string) hash_file('sha256', $file)], 'ContentType' => 'application/octet-stream']);
    }

    /**
     * Drop SQL snapshot versions older than the cutoff, always keeping the newest few so a stalled schedule
     * cannot empty the bucket. Called only after a successful upload, never to "fix" a failed backup.
     */
    public function pruneBackups(int $cutoff, int $keepNewest = 3): int
    {
        $versions = $this->versions('backups/');
        usort($versions, static fn (array $a, array $b): int => $b['modified'] <=> $a['modified']);
        $keep = array_slice(array_values(array_unique(array_column($versions, 'key'))), 0, $keepNewest);
        $stale = array_values(array_filter($versions, static fn (array $v): bool => $v['modified'] < $cutoff && !in_array($v['key'], $keep, true)));

        return $this->deleteVersions('backups/', $stale);
    }

    public function assertVersioned(): void
    {
        if ($this->s3()->getBucketVersioning(['Bucket' => $this->bucket()])['Status'] !== 'Enabled') {
            throw new RuntimeException('Backup bucket versioning must be enabled.');
        }
    }

    /**
     * Current objects under a prefix: key => last-modified timestamp.
     *
     * @return array<string, int>
     */
    public function listObjects(string $prefix): array
    {
        $found = [];
        foreach ($this->s3()->getPaginator('ListObjectsV2', ['Bucket' => $this->bucket(), 'Prefix' => $prefix]) as $page) {
            foreach ($page['Contents'] ?? [] as $object) {
                $modified = $object['LastModified'] ?? null;
                $found[(string) $object['Key']] = $modified instanceof \DateTimeInterface ? $modified->getTimestamp() : 0;
            }
        }

        return $found;
    }

    /**
     * Every stored version (not delete markers) under a prefix.
     *
     * @return list<array{key: string, version: string, latest: bool, modified: int}>
     */
    public function versions(string $prefix): array
    {
        $found = [];
        foreach ($this->s3()->getPaginator('ListObjectVersions', ['Bucket' => $this->bucket(), 'Prefix' => $prefix]) as $page) {
            foreach ($page['Versions'] ?? [] as $version) {
                $modified = $version['LastModified'] ?? null;
                $found[] = ['key' => (string) $version['Key'], 'version' => (string) $version['VersionId'], 'latest' => (bool) ($version['IsLatest'] ?? false),
                    'modified' => $modified instanceof \DateTimeInterface ? $modified->getTimestamp() : 0];
            }
        }

        return $found;
    }

    /** Permanently removes the given versions; only the retention policies in this namespace call it. */
    /** @param list<array{key: string, version: string}> $versions */
    public function deleteVersions(string $prefix, array $versions): int
    {
        $count = 0;
        foreach (array_chunk($versions, 500) as $chunk) {
            $objects = [];
            foreach ($chunk as $version) {
                if (!str_starts_with($version['key'], $prefix)) {
                    throw new RuntimeException('Refusing to delete outside the backup namespace.');
                }
                $objects[] = ['Key' => $version['key'], 'VersionId' => $version['version']];
            }
            $result = $this->s3()->deleteObjects(['Bucket' => $this->bucket(), 'Delete' => ['Objects' => $objects, 'Quiet' => true]]);
            if (($result['Errors'] ?? []) !== []) {
                throw new RuntimeException('Offsite retention could not delete some backup versions.');
            }
            $count += count($objects);
        }

        return $count;
    }

    public function download(string $key, string $path): string
    {
        $result = $this->s3()->getObject(['Bucket' => $this->bucket(), 'Key' => $key, 'SaveAs' => $path]);

        return (string) ($result['Metadata']['sha256'] ?? '');
    }

    private function bucket(): string
    {
        return $this->config->env()->required('BACKUP_S3_BUCKET');
    }

    private function s3(): S3Client
    {
        if ($this->client !== null) {
            return $this->client;
        }
        $env = $this->config->env();
        $endpoint = $env->required('BACKUP_S3_ENDPOINT');
        $url = parse_url($endpoint);
        if (!is_array($url) || ($url['scheme'] ?? '') !== 'https' || !isset($url['host']) || isset($url['user']) || isset($url['pass'])) {
            throw new RuntimeException('Offsite backup endpoint must use HTTPS without embedded credentials.');
        }

        return $this->client = new S3Client(['version' => 'latest', 'region' => $env->string('BACKUP_S3_REGION', 'us-east-1'),
            'endpoint' => $endpoint, 'use_path_style_endpoint' => true,
            'credentials' => ['key' => $env->required('BACKUP_S3_KEY'), 'secret' => $env->required('BACKUP_S3_SECRET')],
            'http' => ['connect_timeout' => 5, 'timeout' => 120], 'retries' => 2]);
    }

    /** Check media recovery protection without changing bucket policy or accessing private media contents. */
    public function verifyMediaVersioning(): void
    {
        if ($this->config->string('media.disk') !== 's3') {
            throw new RuntimeException('Versioned offsite media storage is not configured.');
        }
        $endpoint = $this->config->string('media.s3.endpoint');
        $url = parse_url($endpoint);
        if (!is_array($url) || ($url['scheme'] ?? '') !== 'https' || !isset($url['host']) || isset($url['user']) || isset($url['pass'])) {
            throw new RuntimeException('Media backup endpoint must use HTTPS without embedded credentials.');
        }
        $client = $this->client ?? new S3Client(['version' => 'latest', 'region' => $this->config->string('media.s3.region'),
            'endpoint' => $endpoint, 'use_path_style_endpoint' => $this->config->bool('media.s3.path_style'),
            'credentials' => ['key' => $this->config->env()->required('S3_KEY'), 'secret' => $this->config->env()->required('S3_SECRET')],
            'http' => ['connect_timeout' => 5, 'timeout' => 30], 'retries' => 2]);
        if ($client->getBucketVersioning(['Bucket' => $this->config->string('media.s3.bucket')])['Status'] !== 'Enabled') {
            throw new RuntimeException('Media bucket versioning must be enabled.');
        }
    }
}
