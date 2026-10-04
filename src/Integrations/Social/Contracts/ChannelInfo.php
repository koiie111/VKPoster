<?php

declare(strict_types=1);

namespace App\Integrations\Social\Contracts;

/**
 * A channel as the platform describes it, after checking what our account may do there.
 */
final class ChannelInfo
{
    /**
     * @param string $kind channel | group
     * @param array<string, bool> $rights post, edit, delete, pin
     */
    public function __construct(
        public readonly string $externalId,
        public readonly string $title,
        public readonly ?string $username,
        public readonly string $kind,
        public readonly array $rights,
        public readonly ?string $avatarFileId = null,
    ) {
    }
}
