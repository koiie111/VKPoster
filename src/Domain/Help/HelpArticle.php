<?php

declare(strict_types=1);

namespace App\Domain\Help;

/**
 * One help article: a Markdown file in `resources/help/`. The title is its first heading, the summary its first paragraph.
 */
final class HelpArticle
{
    public function __construct(
        public readonly string $slug,
        public readonly string $title,
        public readonly string $summary,
        public readonly string $markdown,
    ) {
    }
}
