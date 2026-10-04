<?php

declare(strict_types=1);

namespace App\Domain\Media;

use App\Kernel\Config;
use App\Kernel\Security\Signer;

/**
 * URLs of library files. In the app they are plain paths (`/media/{id}/{variant}`, checked against the viewer's
 * session). For networks that download media themselves (Instagram), `signed()` gives an absolute link that needs no
 * session but expires and cannot be forged or altered.
 */
final class MediaUrls
{
    public function __construct(
        private readonly Signer $signer,
        private readonly Config $config,
        private readonly MediaLimits $limits,
    ) {
    }

    public function path(Media $media, string $variant = 'original'): string
    {
        return '/media/' . $media->publicId . '/' . $variant;
    }

    /**
     * Absolute, time-limited link for a variant slug (`original`, `thumb`, `1x1-wm`, ...).
     */
    public function signed(Media $media, string $variant = 'original', ?int $ttlSeconds = null): string
    {
        return rtrim($this->config->string('app.url'), '/') . $this->signer->signUrl($this->path($media, $variant), $ttlSeconds ?? $this->limits->signedUrlTtl);
    }
}
