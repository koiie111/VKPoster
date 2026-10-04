<?php

declare(strict_types=1);

namespace App\Integrations\Social\Contracts;

use DateTimeImmutable;

/**
 * Optional ability of an adapter: find out whether a post whose publication ended in an `unknown_outcome` did reach the channel, by
 * looking at the channel's latest posts. The pipeline asks before it bothers the owner; a found post becomes a normal success.
 */
interface OutcomeVerifier
{
    /**
     * @param DateTimeImmutable $since when the attempt began (the post cannot be older)
     * @return PublishResult|null the published post, or null when it is not there (or cannot be told apart)
     * @throws PlatformError when the channel could not be read
     */
    public function findPublished(PublishRequest $request, string $externalChannelId, Credential $credential, DateTimeImmutable $since): ?PublishResult;
}
