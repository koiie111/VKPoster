<?php

declare(strict_types=1);

namespace App\Integrations\Social\Contracts;

/**
 * Optional ability of an adapter: leave the author's "first comment" under a published post (links and hashtags that should not
 * clutter the post itself). Only networks whose `Capabilities::$firstComment` is true implement it.
 */
interface CommentingAdapter
{
    /**
     * @throws PlatformError
     */
    public function comment(PublishResult $published, string $externalChannelId, Credential $credential, string $text): void;
}
