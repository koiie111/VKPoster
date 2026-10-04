<?php

declare(strict_types=1);

namespace App\Domain\Channel;

/**
 * Whose credentials publish to a channel: our bot (one token for everybody, from the environment), the customer's own bot, or the
 * customer's own account on the network (VK: OAuth tokens stored encrypted in `platform_credentials` and refreshed by us).
 */
enum ChannelMode: string
{
    case SharedBot = 'shared_bot';
    case OwnBot = 'own_bot';
    case Account = 'account';
}
