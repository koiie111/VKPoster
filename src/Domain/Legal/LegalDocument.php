<?php

declare(strict_types=1);

namespace App\Domain\Legal;

/**
 * One legal page (offer, privacy policy, cookies, requisites) read from `resources/legal/<slug>.md`.
 * `version` is a date (YYYY-MM-DD) the owner raises whenever the text changes; `required` documents must be accepted at sign-up.
 */
final class LegalDocument
{
    public function __construct(
        public readonly string $slug,
        public readonly string $title,
        public readonly string $version,
        public readonly bool $required,
        public readonly string $markdown,
    ) {
    }

    /**
     * Number of `[[...]]` places the owner has not filled in yet.
     */
    public function placeholders(): int
    {
        return (int) preg_match_all('/\[\[[^\]]+\]\]/u', $this->markdown);
    }

    public function isDraft(): bool
    {
        return $this->placeholders() > 0;
    }
}
