<?php

declare(strict_types=1);

namespace App\Kernel\Http;

/**
 * One file from `$_FILES`. The client-supplied name and MIME type are never trusted:
 * `mimeType()` sniffs the content.
 */
final class UploadedFile
{
    public function __construct(
        public readonly string $clientName,
        public readonly string $path,
        public readonly int $size,
        public readonly int $error,
    ) {
    }

    public function isValid(): bool
    {
        return $this->error === UPLOAD_ERR_OK && $this->path !== '' && is_file($this->path);
    }

    /**
     * MIME type detected from file content via `finfo` (null if the file is unusable).
     */
    public function mimeType(): ?string
    {
        if (!$this->isValid()) {
            return null;
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($this->path);

        return $mime === false ? null : $mime;
    }
}
