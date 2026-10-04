<?php

declare(strict_types=1);

namespace App\Integrations\Storage;

use App\Kernel\Config;
use Aws\S3\S3Client;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;

/**
 * Builds the configured `MediaStorage` (`MEDIA_DISK=local|s3`).
 */
final class MediaStorageFactory
{
    public static function create(Config $config, string $basePath): MediaStorage
    {
        if ($config->string('media.disk', 'local') === 's3') {
            $s3 = [
                'version' => 'latest',
                'region' => $config->string('media.s3.region', 'us-east-1'),
                'credentials' => ['key' => $config->string('media.s3.key'), 'secret' => $config->string('media.s3.secret')],
                'use_path_style_endpoint' => $config->bool('media.s3.path_style', true),
            ];
            $endpoint = $config->string('media.s3.endpoint');
            if ($endpoint !== '') {
                $s3['endpoint'] = $endpoint;
            }

            return new FlysystemMediaStorage(new Filesystem(new AwsS3V3Adapter(new S3Client($s3), $config->string('media.s3.bucket'))));
        }

        $root = $config->string('media.local_root', 'storage/media');
        $root = str_starts_with($root, '/') ? $root : rtrim($basePath, '/') . '/' . $root;

        // Files readable by the application user only.
        $visibility = PortableVisibilityConverter::fromArray([
            'file' => ['public' => 0640, 'private' => 0600],
            'dir' => ['public' => 0750, 'private' => 0700],
        ]);

        return new FlysystemMediaStorage(new Filesystem(
            new LocalFilesystemAdapter($root, $visibility),
            ['visibility' => 'private', 'directory_visibility' => 'private'],
        ));
    }
}
