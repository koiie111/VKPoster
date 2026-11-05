<?php

declare(strict_types=1);

namespace App\Integrations\Social\Contracts;

/** Sets a cover after the network has processed the uploaded video. */
interface VideoThumbnailAdapter
{
    public function setThumbnail(string $remoteId, string $path, Credential $credential): void;
}
