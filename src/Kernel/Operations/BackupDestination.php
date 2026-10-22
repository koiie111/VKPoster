<?php

declare(strict_types=1);

namespace App\Kernel\Operations;

use App\Kernel\Config;
use Aws\S3\S3Client;
use RuntimeException;

/** Uploads encrypted archives only, after verifying offsite bucket versioning. */
final class BackupDestination
{
    public function __construct(private readonly Config $config, private readonly ?S3Client $client = null)
    {
    }

    /** Transport errors propagate: failed offsite delivery must never look like a successful backup. */
    public function upload(string $file): void
    {
        if (!is_file($file) || is_link($file) || !str_ends_with($file, '.ezb') || file_get_contents($file, false, null, 0, 10) !== "EZBACKUP1\n") {
            throw new RuntimeException('Only encrypted backup archives may be uploaded.');
        }
        $env = $this->config->env();
        $endpoint = $env->required('BACKUP_S3_ENDPOINT');
        $url = parse_url($endpoint);
        if (!is_array($url) || ($url['scheme'] ?? '') !== 'https' || !isset($url['host']) || isset($url['user']) || isset($url['pass'])) {
            throw new RuntimeException('Offsite backup endpoint must use HTTPS without embedded credentials.');
        }
        $s3 = $this->client ?? new S3Client(['version' => 'latest', 'region' => $env->string('BACKUP_S3_REGION', 'us-east-1'),
            'endpoint' => $endpoint, 'use_path_style_endpoint' => true,
            'credentials' => ['key' => $env->required('BACKUP_S3_KEY'), 'secret' => $env->required('BACKUP_S3_SECRET')],
            'http' => ['connect_timeout' => 5, 'timeout' => 120], 'retries' => 2]);
        $bucket = $env->required('BACKUP_S3_BUCKET');
        if ($s3->getBucketVersioning(['Bucket' => $bucket])['Status'] !== 'Enabled') {
            throw new RuntimeException('Backup bucket versioning must be enabled.');
        }
        $s3->putObject(['Bucket' => $bucket, 'Key' => 'backups/' . basename($file), 'SourceFile' => $file,
            'Metadata' => ['sha256' => (string) hash_file('sha256', $file)], 'ContentType' => 'application/octet-stream']);
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
