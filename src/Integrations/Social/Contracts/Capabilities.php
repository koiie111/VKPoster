<?php

declare(strict_types=1);

namespace App\Integrations\Social\Contracts;

/**
 * What a platform can publish. The editor reads these numbers to warn before scheduling, adapters check them in `validate()`.
 */
final class Capabilities
{
    public function __construct(
        public readonly int $maxText,
        public readonly int $maxCaption,
        public readonly int $maxMedia,
        public readonly bool $albums,
        public readonly bool $polls,
        public readonly bool $buttons,
        public readonly bool $silent,
        public readonly bool $pin,
        public readonly bool $delete,
        public readonly bool $firstComment,
        public readonly int $maxFileBytes,
        public readonly bool $disablePreview = false,
        /** How the editor's markup must be handed over: `plain` (markers stripped) or `html` (the platform's HTML subset). */
        public readonly string $textFormat = 'plain',
        /** How many posts one channel may publish a day (0 = no limit known); the editor warns before it is exceeded. */
        public readonly int $maxPostsPerDay = 0,
    ) {
    }
}
