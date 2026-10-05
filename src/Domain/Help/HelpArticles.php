<?php

declare(strict_types=1);

namespace App\Domain\Help;

use App\Kernel\Database\Connection;

/**
 * The knowledge base: static Markdown articles under `resources/help/`, served as `/help` pages. Writing a new article is adding a file
 * (the first line is `# Title`); `ORDER` only decides which ones come first in the list.
 */
final class HelpArticles
{
    /** Articles listed first, in this order; the others follow alphabetically. */
    private const ORDER = ['getting-started', 'connect-telegram', 'connect-vk', 'connect-max', 'schedule-post', 'billing', 'troubleshooting'];

    /** @var array<string, HelpArticle>|null */
    private ?array $articles = null;

    public function __construct(private readonly string $dir, private readonly ?Connection $db = null)
    {
    }

    /**
     * @return list<HelpArticle>
     */
    public function all(): array
    {
        return array_values($this->load());
    }

    public function find(string $slug): ?HelpArticle
    {
        return $this->load()[$slug] ?? null;
    }

    /**
     * @return array<string, HelpArticle>
     */
    private function load(): array
    {
        if ($this->articles !== null) {
            return $this->articles;
        }
        $found = [];
        foreach (self::files($this->dir) as $file) {
            $slug = basename($file, '.md');
            $source = file_get_contents($file);
            if ($source === false || preg_match('/^[a-z0-9-]+$/', $slug) !== 1) {
                continue;
            }
            $found[$slug] = self::article($slug, $source);
        }
        // Articles written in the admin area replace a file of the same name or add a new one.
        foreach ($this->edited() as $slug => $source) {
            $found[$slug] = self::article($slug, $source);
        }
        $ordered = [];
        foreach (self::ORDER as $slug) {
            if (isset($found[$slug])) {
                $ordered[$slug] = $found[$slug];
            }
        }
        ksort($found);

        return $this->articles = $ordered + $found;
    }

    /**
     * The live text of every article edited in the admin area (the newest published revision of each).
     *
     * @return array<string, string> slug => Markdown with the title as the first heading
     */
    private function edited(): array
    {
        if ($this->db === null) {
            return [];
        }
        $result = [];
        foreach ($this->db->select("SELECT c.slug, c.title, c.body_md FROM cms_pages c JOIN (SELECT slug, MAX(id) AS id FROM cms_pages WHERE kind = 'help' AND status = 'published' GROUP BY slug) m ON m.id = c.id") as $row) {
            if (preg_match('/^[a-z0-9-]{1,60}$/', (string) $row['slug']) === 1) {
                $result[(string) $row['slug']] = '# ' . $row['title'] . "\n\n" . $row['body_md'];
            }
        }

        return $result;
    }

    /**
     * Build an article from its Markdown (the title is the first `# ` heading, the summary the first plain paragraph).
     */
    private static function article(string $slug, string $source): HelpArticle
    {
        $source = str_replace(["\r\n", "\r"], "\n", $source);
        $title = preg_match('/^#\s+(.+)$/m', $source, $m) === 1 ? trim($m[1]) : $slug;
        $body = trim((string) preg_replace('/^#\s+.+\n/', '', $source, 1));
        $summary = '';
        foreach (self::blocks($body) as $block) {
            $block = trim($block);
            if ($block !== '' && !in_array($block[0], ['#', '-', '*', '1', '`', '>'], true)) {
                $summary = trim((string) preg_replace('/\s+/', ' ', $block));
                break;
            }
        }
        return new HelpArticle($slug, $title, $summary, $body);
    }

    /**
     * @return list<string>
     */
    private static function files(string $dir): array
    {
        $files = glob($dir . '/*.md');

        return $files === false ? [] : $files;
    }

    /**
     * @return list<string>
     */
    private static function blocks(string $body): array
    {
        $blocks = preg_split('/\n\s*\n/', $body);

        return $blocks === false ? [] : $blocks;
    }
}
