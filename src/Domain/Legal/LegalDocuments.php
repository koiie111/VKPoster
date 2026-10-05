<?php

declare(strict_types=1);

namespace App\Domain\Legal;

use App\Kernel\Database\Connection;
use App\Support\Markdown;

/**
 * Registry of the legal pages. The files are the starting point (reviewed and versioned in git); a text published in the admin area
 * (`cms_pages`) replaces the file of the same name. The consent version is the newest version among the required documents, so publishing a
 * new version of one of them makes everybody confirm the new text at the next sign-in.
 */
final class LegalDocuments
{
    /** Order on the site and in the footer. */
    private const SLUGS = ['offer', 'privacy', 'cookies', 'requisites'];

    /** @var array<string, LegalDocument>|null */
    private ?array $documents = null;

    public function __construct(private readonly string $dir, private readonly ?Connection $db = null)
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

        foreach ($this->published() as $slug => $document) {
            $documents[$slug] = $document;
        }
        // The known pages keep their order; a page added in the admin area follows them.
        $ordered = [];
        foreach (self::SLUGS as $slug) {
            if (isset($documents[$slug])) {
                $ordered[$slug] = $documents[$slug];
            }
        }
        ksort($documents);

        return $this->documents = $ordered + $documents;
    }

    /**
     * The live revision of every document edited in the admin area: the newest published one.
     *
     * @return array<string, LegalDocument>
     */
    private function published(): array
    {
        if ($this->db === null) {
            return [];
        }
        $result = [];
        foreach ($this->db->select("SELECT c.slug, c.title, c.version, c.required, c.body_md FROM cms_pages c JOIN (SELECT slug, MAX(id) AS id FROM cms_pages WHERE kind = 'legal' AND status = 'published' GROUP BY slug) m ON m.id = c.id") as $row) {
            $slug = (string) $row['slug'];
            if (preg_match('/^[a-z]{3,20}$/', $slug) !== 1) {
                continue;
            }
            $version = is_string($row['version']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $row['version']) === 1 ? $row['version'] : '0000-00-00';
            $result[$slug] = new LegalDocument($slug, (string) $row['title'], $version, (int) $row['required'] === 1, (string) $row['body_md']);
        }

        return $result;
    }
}
