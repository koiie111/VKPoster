<?php

declare(strict_types=1);

namespace App\Domain\Post;

use App\Integrations\Social\Contracts\PublishRequest;

/**
 * A `PublishRequest` together with the temporary files its media points to. `cleanup()` must run when the call is over, whatever
 * its outcome.
 */
final class BuiltRequest
{
    /**
     * @param list<string> $tempFiles
     */
    public function __construct(public readonly PublishRequest $request, private readonly array $tempFiles)
    {
    }

    public function cleanup(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }
}
