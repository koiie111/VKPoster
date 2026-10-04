<?php

declare(strict_types=1);

namespace App\Domain\Media;

use App\Kernel\Config;

/**
 * Size and safety limits of the library, read from `config/media.php`.
 */
final class MediaLimits
{
    /**
     * @param array<string, array<string, mixed>> $platforms what each platform accepts, by platform key
     */
    public function __construct(
        public readonly int $maxFileBytes,
        public readonly int $quotaBytes,
        public readonly int $maxSide,
        public readonly int $maxVideoSeconds,
        public readonly int $urlTimeout,
        public readonly int $signedUrlTtl,
        public readonly int $thumbSize,
        public readonly array $platforms,
    ) {
    }

    public static function fromConfig(Config $config): self
    {
        /** @var array<string, array<string, mixed>> $platforms */
        $platforms = $config->array('media.platforms');

        return new self(
            $config->int('media.max_file_bytes', 50 * 1024 * 1024),
            $config->int('media.quota_bytes', 500 * 1024 * 1024),
            $config->int('media.max_side', 10000),
            $config->int('media.max_video_seconds', 900),
            $config->int('media.url_timeout', 30),
            $config->int('media.signed_url_ttl', 3600),
            $config->int('media.thumb_size', 320),
            $platforms,
        );
    }
}
