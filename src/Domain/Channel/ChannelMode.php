<?php

declare(strict_types=1);

namespace App\Domain\Channel;

/**
 * Whose bot publishes to a channel: ours (one token for everybody, from the environment) or the customer's own
 * (token stored encrypted in `platform_credentials`).
 */
enum ChannelMode: string
{
    case SharedBot = 'shared_bot';
    case OwnBot = 'own_bot';
}
