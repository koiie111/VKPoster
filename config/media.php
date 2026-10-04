<?php

declare(strict_types=1);

use App\Kernel\Env;

/**
 * Media library settings, read as `media` in `Config`. Size limits live here until plans arrive (stage 10),
 * where the plan decides the library size (`quota_bytes` is only an optional global cap).
 *
 * `platforms` holds what each network accepts, as data: stages 06-12 read it to validate posts and to build
 * variants. Only the keys that exist for a platform are checked.
 */
return static function (Env $env): array {
    $mb = 1024 * 1024;

    return [
        'disk' => $env->string('MEDIA_DISK', 'local'),
        'local_root' => $env->string('MEDIA_LOCAL_ROOT', 'storage/media'),
        's3' => [
            'endpoint' => $env->string('S3_ENDPOINT'),
            'region' => $env->string('S3_REGION', 'us-east-1'),
            'bucket' => $env->string('S3_BUCKET', 'ezposter'),
            'key' => $env->string('S3_KEY'),
            'secret' => $env->string('S3_SECRET'),
            'path_style' => $env->bool('S3_PATH_STYLE', true),
        ],
        'max_file_bytes' => $env->int('MEDIA_MAX_FILE_MB', 50) * $mb,
        // The library size comes from the plan (stage 10); this is only an optional hard cap on top of it (0 = no cap).
        'quota_bytes' => $env->int('MEDIA_QUOTA_MB', 0) * $mb,
        'max_side' => 10000,
        'max_video_seconds' => $env->int('MEDIA_MAX_VIDEO_SECONDS', 900),
        'url_timeout' => $env->int('MEDIA_URL_TIMEOUT', 30),
        'signed_url_ttl' => $env->int('MEDIA_SIGNED_URL_TTL', 3600),
        'thumb_size' => 320,
        'platforms' => [
            'telegram' => [
                'image_bytes' => 10 * $mb, 'image_side_sum' => 10000, 'video_bytes' => 50 * $mb,
                'document_bytes' => 50 * $mb, 'video_codecs' => ['h264', 'hevc', 'vp9'],
            ],
            'vk' => [
                'image_bytes' => 50 * $mb, 'image_side_sum' => 14000, 'video_bytes' => 256 * $mb,
                'document_bytes' => 200 * $mb,
            ],
            'max' => [
                'image_bytes' => 50 * $mb, 'video_bytes' => 250 * $mb, 'document_bytes' => 100 * $mb,
            ],
            'instagram' => [
                'image_bytes' => 8 * $mb, 'image_ratio' => [0.8, 1.91], 'video_bytes' => 100 * $mb,
                'video_seconds' => 90, 'video_codecs' => ['h264'], 'no_documents' => true,
            ],
        ],
    ];
};
