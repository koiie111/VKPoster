<?php

declare(strict_types=1);

namespace App\Integrations\Social\Contracts;

/** An accepted short video can finish processing after the upload worker has returned. */
interface VideoProcessingAdapter
{
    /** Null means that processing is still underway. A failed job throws a classified PlatformError. */
    public function pollVideo(string $remoteId, string $externalChannelId, Credential $credential): ?PublishResult;
}
