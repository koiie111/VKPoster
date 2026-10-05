<?php

declare(strict_types=1);

namespace App\Domain\Legal;

use App\Support\Markdown;

/**
 * Registry of the legal pages. The files are the source of truth (reviewed and versioned in git); the consent version is the
 * newest version among the required documents, so raising one of them makes everybody confirm the new text at the next sign-in.
 */
final class LegalDocuments
{
    /** Order on the site and in the footer. */
    private const SLUGS = ['offer', 'privacy', 'cookies', 'requisites'];

    /** @var array<string, LegalDocument>|null */
    private ?array $documents = null;

    public function __construct(private readonly string $dir)
    {
    }

    /**
     * @return list<LegalDocument>
     */
    public function all(): array
    {
        return array_values($this->load());
    }

    public function find(string $slug): ?LegalDocument
    {
        return $this->load()[$slug] ?? null;
    }

    /**
     * @return list<LegalDocument> documents a person agrees to when signing up
     */
    public function required(): array
    {
        return array_values(array_filter($this->load(), static fn (LegalDocument $d): bool => $d->required));
    }

    /**
     * The value stored in `users.consent_version`: the newest version of any required document.
     */
    public function consentVersion(): string
    {
        $versions = array_map(static fn (LegalDocument $d): string => $d->version, $this->required());

        return $versions === [] ? '0000-00-00' : max($versions);
    }

    /**
     * @return array<string, LegalDocument>
     */
    private function load(): array
    {
        if ($this->documents !== null) {
            return $this->documents;
        }
        $documents = [];
        foreach (self::SLUGS as $slug) {
            $file = $this->dir . '/' . $slug . '.md';
            $source = is_file($file) ? file_get_contents($file) : false;
            if ($source === false) {
                continue;
            }
            [$meta, $body] = Markdown::frontMatter($source);
            $documents[$slug] = new LegalDocument(
                $slug,
                $meta['title'] ?? $slug,
                preg_match('/^\d{4}-\d{2}-\d{2}$/', $meta['version'] ?? '') === 1 ? $meta['version'] : '0000-00-00',
                ($meta['required'] ?? 'no') === 'yes',
                $body,
            );
        }

        return $this->documents = $documents;
    }
}
