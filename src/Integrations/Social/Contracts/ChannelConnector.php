<?php

declare(strict_types=1);

namespace App\Integrations\Social\Contracts;

/**
 * Connecting a channel with a secret the customer provides (a bot token). Flows that start on the platform's side
 * (the shared bot receiving a one-time code) are handled by the platform's own update handler.
 */
interface ChannelConnector
{
    public function platform(): Platform;

    /**
     * Check the secret and that it may publish to `$reference` (an @username, a link or an id, as the customer typed it).
     *
     * @throws PlatformError with a user-facing message when the secret is wrong or the account lacks rights
     */
    public function connect(Credential $credential, string $reference): ChannelInfo;
}
