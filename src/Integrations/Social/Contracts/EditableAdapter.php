<?php

declare(strict_types=1);

namespace App\Integrations\Social\Contracts;

/**
 * Optional ability of an adapter: change the text of a post that is already published. Networks that cannot do it simply do not
 * implement this interface, and the editor then offers no editing for their publications.
 */
interface EditableAdapter
{
    /**
     * @param bool $hasMedia whether the published post carries files (then the text is their caption)
     * @throws PlatformError
     */
    public function edit(PublishResult $published, string $externalChannelId, Credential $credential, PublishRequest $request, bool $hasMedia): void;
}
