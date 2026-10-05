<?php

declare(strict_types=1);

namespace App\Domain\Content;

use App\Domain\Help\HelpArticles;
use App\Domain\Legal\LegalDocuments;
use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;
use DateTimeImmutable;

/**
 * Editing of legal documents and help articles in the admin area. A save makes a new revision (`cms_pages`); a revision is either a draft or
 * published, and the newest published one is what the site shows. Earlier revisions stay as history and can be published again.
 * Publishing a new version of a document that people must accept makes the next sign-in ask for the new consent
 * (`LegalDocuments::consentVersion()` follows the newest version date), which is what a legal text change needs.
 * Markdown is shown through the site's own converter, which escapes raw HTML: nothing typed here can inject markup.
 */
final class Documents
{
    public const KINDS = ['legal' => 'Юридический документ', 'help' => 'Статья справки'];

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly LegalDocuments $legal,
        private readonly HelpArticles $help,
    ) {
    }

    /**
     * Every document that exists: written in a file, edited here, or both.
     *
     * @return list<array{kind: string, slug: string, title: string, version: ?string, required: bool, edited: bool, drafts: int}>
     */
    public function all(): array
    {
        $edited = [];
        $drafts = [];
        foreach ($this->db->select("SELECT kind, slug, SUM(status = 'published') AS p, SUM(status = 'draft') AS d FROM cms_pages GROUP BY kind, slug") as $row) {
            $edited[$row['kind'] . ':' . $row['slug']] = (int) $row['p'] > 0;
            $drafts[$row['kind'] . ':' . $row['slug']] = (int) $row['d'];
        }
        $result = [];
        $seen = [];
        foreach ($this->legal->all() as $d) {
            $key = 'legal:' . $d->slug;
            $seen[$key] = true;
            $result[] = ['kind' => 'legal', 'slug' => $d->slug, 'title' => $d->title, 'version' => $d->version, 'required' => $d->required, 'edited' => $edited[$key] ?? false, 'drafts' => $drafts[$key] ?? 0];
        }
        foreach ($this->help->all() as $a) {
            $key = 'help:' . $a->slug;
            $seen[$key] = true;
            $result[] = ['kind' => 'help', 'slug' => $a->slug, 'title' => $a->title, 'version' => null, 'required' => false, 'edited' => $edited[$key] ?? false, 'drafts' => $drafts[$key] ?? 0];
        }
        // A document that exists only as an unpublished draft.
        foreach ($drafts as $key => $count) {
            if (!isset($seen[$key])) {
                [$kind, $slug] = explode(':', $key, 2);
                $title = (string) ($this->db->select('SELECT title FROM cms_pages WHERE kind = ? AND slug = ? ORDER BY id DESC LIMIT 1', [$kind, $slug])[0]['title'] ?? $slug);
                $result[] = ['kind' => $kind, 'slug' => $slug, 'title' => $title, 'version' => null, 'required' => false, 'edited' => false, 'drafts' => $count];
            }
        }

        return $result;
    }

    /**
     * What the editor opens: the newest draft if there is one, otherwise the live text.
     *
     * @return array{kind: string, slug: string, title: string, version: ?string, required: bool, body: string, draft: bool}|null
     */
    public function working(string $kind, string $slug): ?array
    {
        $draft = $this->db->select("SELECT title, version, required, body_md FROM cms_pages WHERE kind = ? AND slug = ? AND status = 'draft' ORDER BY id DESC LIMIT 1", [$kind, $slug])[0] ?? null;
        if ($draft !== null) {
            return ['kind' => $kind, 'slug' => $slug, 'title' => (string) $draft['title'], 'version' => is_string($draft['version']) ? $draft['version'] : null, 'required' => (int) $draft['required'] === 1, 'body' => (string) $draft['body_md'], 'draft' => true];
        }

        return $this->live($kind, $slug);
    }

    /**
     * The text the site shows now.
     *
     * @return array{kind: string, slug: string, title: string, version: ?string, required: bool, body: string, draft: bool}|null
     */
    public function live(string $kind, string $slug): ?array
    {
        if ($kind === 'legal') {
            $d = $this->legal->find($slug);

            return $d === null ? null : ['kind' => 'legal', 'slug' => $slug, 'title' => $d->title, 'version' => $d->version, 'required' => $d->required, 'body' => $d->markdown, 'draft' => false];
        }
        $a = $this->help->find($slug);

        return $a === null ? null : ['kind' => 'help', 'slug' => $slug, 'title' => $a->title, 'version' => null, 'required' => false, 'body' => $a->markdown, 'draft' => false];
    }

    /**
     * Save a revision. `$publish` makes it live (and ends older drafts); otherwise it is a draft.
     *
     * @return list<string> problems in the editor's words; empty when saved
     */
    public function save(string $kind, string $slug, string $title, string $version, bool $required, string $body, bool $publish, ?int $actorId): array
    {
        $errors = [];
        if (!isset(self::KINDS[$kind])) {
            return ['Выберите вид документа.'];
        }
        if (preg_match($kind === 'legal' ? '/^[a-z]{3,20}$/' : '/^[a-z0-9-]{1,60}$/', $slug) !== 1) {
            $errors[] = $kind === 'legal' ? 'Адрес документа — от 3 до 20 латинских букв.' : 'Адрес статьи — латинские буквы, цифры и дефис.';
        }
        $title = trim($title);
        if (mb_strlen($title) < 3 || mb_strlen($title) > 200) {
            $errors[] = 'Название — от 3 до 200 символов.';
        }
        if (trim($body) === '' || mb_strlen($body) > 200000) {
            $errors[] = 'Напишите текст (до 200 000 символов).';
        }
        if ($kind === 'legal') {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $version) !== 1 || DateTimeImmutable::createFromFormat('!Y-m-d', $version) === false) {
                $errors[] = 'Версия документа — дата вида 2026-10-05.';
            } elseif ($publish && ($live = $this->live('legal', $slug)) !== null && $required === $live['required'] && $version <= (string) $live['version'] && $body !== $live['body']) {
                $errors[] = 'Версия должна быть новее действующей (' . $live['version'] . '): иначе люди не увидят запрос на новое согласие.';
            }
        }
        if ($errors !== []) {
            return $errors;
        }
        $now = DbTime::format($this->clock->now());
        $this->db->transaction(function (Connection $db) use ($kind, $slug, $title, $version, $required, $body, $publish, $actorId, $now): void {
            $db->table('cms_pages')->insert([
                'kind' => $kind,
                'slug' => $slug,
                'title' => $title,
                'version' => $kind === 'legal' ? $version : null,
                'required' => $kind === 'legal' && $required ? 1 : 0,
                'body_md' => $body,
                'status' => $publish ? 'published' : 'draft',
                'created_by' => $actorId,
                'created_at' => $now,
                'published_at' => $publish ? $now : null,
            ]);
            if ($publish) {
                // Older drafts of the same document are replaced by what was just published.
                $db->execute("DELETE FROM cms_pages WHERE kind = ? AND slug = ? AND status = 'draft'", [$kind, $slug]);
            }
        });

        return [];
    }

    /**
     * Revisions of a document, newest first.
     *
     * @return list<array{id: int, title: string, version: ?string, status: string, created_at: DateTimeImmutable, published_at: ?DateTimeImmutable, author: ?string}>
     */
    public function history(string $kind, string $slug): array
    {
        $rows = $this->db->select(
            'SELECT c.id, c.title, c.version, c.status, c.created_at, c.published_at, u.name AS author FROM cms_pages c LEFT JOIN users u ON u.id = c.created_by WHERE c.kind = ? AND c.slug = ? ORDER BY c.id DESC LIMIT 50',
            [$kind, $slug],
        );

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'title' => (string) $r['title'],
            'version' => is_string($r['version']) ? $r['version'] : null,
            'status' => (string) $r['status'],
            'created_at' => DbTime::parse($r['created_at']) ?? new DateTimeImmutable('@0'),
            'published_at' => DbTime::parse($r['published_at']),
            'author' => is_string($r['author']) ? $r['author'] : null,
        ], $rows);
    }

    /**
     * Put a revision from the history back into the editor as a new draft (publishing it again is a separate step).
     */
    public function restore(int $revisionId, ?int $actorId): bool
    {
        $row = $this->db->select('SELECT kind, slug, title, version, required, body_md FROM cms_pages WHERE id = ?', [$revisionId])[0] ?? null;
        if ($row === null) {
            return false;
        }
        $this->db->table('cms_pages')->insert([
            'kind' => $row['kind'], 'slug' => $row['slug'], 'title' => $row['title'], 'version' => $row['version'], 'required' => $row['required'],
            'body_md' => $row['body_md'], 'status' => 'draft', 'created_by' => $actorId, 'created_at' => DbTime::format($this->clock->now()),
        ]);

        return true;
    }

    /**
     * Throw an unpublished draft away.
     */
    public function discardDraft(string $kind, string $slug): void
    {
        $this->db->execute("DELETE FROM cms_pages WHERE kind = ? AND slug = ? AND status = 'draft'", [$kind, $slug]);
    }
}
