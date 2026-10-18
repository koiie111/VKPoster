<?php

declare(strict_types=1);

namespace App\Domain\Link;

use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;
use PDOException;
use Symfony\Component\Uid\Ulid;

/**
 * Short links: the 7-character code (base62 from the system's secure random source, 62^7 ≈ 3.5 trillion, so codes can not be guessed
 * one by one), the address it leads to, and the clicks. A link made for a post variant is unique per target address, so a retried
 * publication gets the same code. A click stores the time and a salted, daily-changing, truncated hash of the visitor, never the
 * address itself.
 */
final class ShortLinks
{
    public const CODE_LENGTH = 7;
    private const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    private const BOTS = '/bot|crawl|spider|slurp|preview|fetch|facebookexternalhit|telegram|whatsapp|vkshare|skypeuripreview|curl|wget|python-requests|okhttp|go-http|headless|monitor|check/i';

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly UrlGuard $guard,
        private readonly string $publicBase,
        private readonly string $shortDomain,
        private readonly string $salt,
        private readonly int $clickRetentionDays = 400,
    ) {
    }

    /** The address people see for a code. */
    public function urlFor(string $code): string
    {
        return $this->shortDomain !== '' ? 'https://' . $this->shortDomain . '/' . $code : rtrim($this->publicBase, '/') . '/s/' . $code;
    }

    /** What a short address looks like, for measuring the length of a post before the real code exists. */
    public function sampleUrl(): string
    {
        return $this->urlFor(str_repeat('x', self::CODE_LENGTH));
    }

    /**
     * The short code for an address in one post variant, made on first use.
     *
     * @throws LinkException when the address is not allowed
     */
    public function forVariant(int $workspaceId, int $variantId, string $platform, string $url): string
    {
        $problem = $this->guard->problem($url);
        if ($problem !== null) {
            throw new LinkException($problem);
        }
        $hash = hash('sha256', $url);
        $existing = $this->db->select('SELECT code FROM short_links WHERE variant_id = ? AND target_hash = ?', [$variantId, $hash])[0] ?? null;
        if ($existing !== null) {
            return (string) $existing['code'];
        }
        try {
            return $this->insert($workspaceId, $variantId, $platform, $url, $hash, null);
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }
            // Two workers raced for the same variant and address: the other one's code is the one to use.
            $row = $this->db->select('SELECT code FROM short_links WHERE variant_id = ? AND target_hash = ?', [$variantId, $hash])[0] ?? null;

            return $row !== null ? (string) $row['code'] : throw $e;
        }
    }

    /**
     * A link made by hand on the links page (not tied to a post).
     *
     * @throws LinkException when the address is not allowed or the workspace made too many today
     */
    public function createManual(int $workspaceId, int $userId, string $url, int $perDay): string
    {
        $problem = $this->guard->problem($url);
        if ($problem !== null) {
            throw new LinkException($problem);
        }
        $today = DbTime::format($this->clock->now()->setTime(0, 0));
        $made = (int) ($this->db->select('SELECT COUNT(*) AS c FROM short_links WHERE workspace_id = ? AND variant_id IS NULL AND created_at >= ?', [$workspaceId, $today])[0]['c'] ?? 0);
        if ($made >= $perDay) {
            throw new LinkException('На сегодня лимит коротких ссылок исчерпан. Попробуйте завтра.');
        }

        return $this->insert($workspaceId, null, '', $url, hash('sha256', $url), $userId);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $code): ?array
    {
        if (preg_match('/^[0-9A-Za-z]{' . self::CODE_LENGTH . '}$/', $code) !== 1) {
            return null;
        }

        return $this->db->select('SELECT * FROM short_links WHERE code = ?', [$code])[0] ?? null;
    }

    /**
     * One link of a workspace by its public id (null when it belongs to another workspace).
     *
     * @return array<string, mixed>|null
     */
    public function findInWorkspace(int $workspaceId, string $publicId): ?array
    {
        return $this->db->select('SELECT * FROM short_links WHERE public_id = ? AND workspace_id = ?', [$publicId, $workspaceId])[0] ?? null;
    }

    /**
     * Any link by public id (admin area only).
     *
     * @return array<string, mixed>|null
     */
    public function findAny(string $publicId): ?array
    {
        return $this->db->select('SELECT * FROM short_links WHERE public_id = ?', [$publicId])[0] ?? null;
    }

    /**
     * Counts a visit. A failure here must never stop the redirect, so the caller ignores exceptions.
     *
     * @param array<string, mixed> $link
     */
    public function recordClick(array $link, string $ip, string $userAgent): void
    {
        $now = $this->clock->now();
        $bot = $userAgent === '' || preg_match(self::BOTS, $userAgent) === 1;
        $visitor = substr(hash_hmac('sha256', $now->format('Y-m-d') . '|' . $ip . '|' . $userAgent, $this->salt), 0, 16);
        $this->db->execute('INSERT INTO short_link_clicks (link_id, clicked_at, visitor_hash, is_bot) VALUES (?, ?, ?, ?)', [$link['id'], DbTime::format($now), $visitor, $bot ? 1 : 0]);
        $this->db->execute(
            'UPDATE short_links SET clicks = clicks + 1, human_clicks = human_clicks + ?, last_click_at = ? WHERE id = ?',
            [$bot ? 0 : 1, DbTime::format($now), $link['id']],
        );
    }

    public function setDisabled(int $id, bool $disabled, string $reason = ''): void
    {
        $this->db->execute(
            'UPDATE short_links SET disabled_at = ?, disabled_reason = ? WHERE id = ?',
            [$disabled ? DbTime::format($this->clock->now()) : null, $disabled && $reason !== '' ? mb_substr($reason, 0, 200) : null, $id],
        );
    }

    /**
     * The newest links of a workspace with their click counts.
     *
     * @return list<array<string, mixed>>
     */
    public function forWorkspace(int $workspaceId, int $limit = 100): array
    {
        return $this->db->select('SELECT * FROM short_links WHERE workspace_id = ? ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)), [$workspaceId]);
    }

    /**
     * Links for the admin area, newest first, optionally filtered by a part of the code or the address.
     *
     * @return list<array<string, mixed>>
     */
    public function search(string $term, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        if ($term === '') {
            return $this->db->select('SELECT l.*, w.name AS workspace_name FROM short_links l JOIN workspaces w ON w.id = l.workspace_id ORDER BY l.id DESC LIMIT ' . $limit);
        }
        $like = '%' . addcslashes($term, '%_\\') . '%';

        return $this->db->select(
            'SELECT l.*, w.name AS workspace_name FROM short_links l JOIN workspaces w ON w.id = l.workspace_id WHERE l.code = ? OR l.target_url LIKE ? ORDER BY l.id DESC LIMIT ' . $limit,
            [$term, $like],
        );
    }

    /**
     * Disables every link that leads to a domain on the blocklist; returns how many were switched off.
     */
    public function disableBlocked(): int
    {
        $count = 0;
        foreach ($this->guard->blocked() as $domain) {
            $count += $this->db->execute(
                'UPDATE short_links SET disabled_at = ?, disabled_reason = ? WHERE disabled_at IS NULL AND (target_url LIKE ? OR target_url LIKE ?)',
                [DbTime::format($this->clock->now()), 'Сайт в списке запрещённых', '%://' . addcslashes($domain, '%_\\') . '/%', '%://%.' . addcslashes($domain, '%_\\') . '/%'],
            );
        }

        return $count;
    }

    /** Deletes clicks older than the retention period (the counters on the links stay). */
    public function pruneClicks(int $days): int
    {
        return $this->db->execute('DELETE FROM short_link_clicks WHERE clicked_at < ?', [DbTime::format($this->clock->now()->modify('-' . max(1, $days) . ' days'))]);
    }

    /** Scheduler entry point. */
    public function prune(): void
    {
        $this->pruneClicks($this->clickRetentionDays);
    }

    /**
     * @throws PDOException
     */
    private function insert(int $workspaceId, ?int $variantId, string $platform, string $url, string $hash, ?int $userId): string
    {
        for ($attempt = 0; $attempt < 6; ++$attempt) {
            $code = self::newCode();
            try {
                $this->db->execute(
                    'INSERT INTO short_links (public_id, code, workspace_id, variant_id, platform, target_url, target_hash, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [(string) new Ulid(), $code, $workspaceId, $variantId, $platform, $url, $hash, $userId, DbTime::format($this->clock->now())],
                );

                return $code;
            } catch (PDOException $e) {
                // A taken code (1062 on the code key) just means: draw another one. Any other duplicate is the caller's to handle.
                if (($e->errorInfo[1] ?? 0) !== 1062 || !str_contains($e->getMessage(), 'uq_short_links_code')) {
                    throw $e;
                }
            }
        }

        throw new LinkException('Не удалось создать короткую ссылку. Попробуйте ещё раз.');
    }

    private static function newCode(): string
    {
        $code = '';
        for ($i = 0; $i < self::CODE_LENGTH; ++$i) {
            $code .= self::ALPHABET[random_int(0, 61)];
        }

        return $code;
    }
}
