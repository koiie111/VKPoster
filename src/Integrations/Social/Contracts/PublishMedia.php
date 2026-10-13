<?php

declare(strict_types=1);

namespace App\Integrations\Social\Contracts;

use App\Domain\Media\MediaKind;

/**
 * One attachment of a post, as a local file: the pipeline copies the library file (or its variant for this platform)
 * to a temporary path before calling the adapter.
 */
final class PublishMedia
{
    public function __construct(
        public readonly MediaKind $kind,
        public readonly string $path,
        public readonly string $filename,
        public readonly string $mime,
        /** Public link the network downloads the file from itself (only for platforms with `Capabilities::$publicMediaUrls`; then `path` is empty). */
        public readonly ?string $url = null,
    ) {
    }
}
