<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Kernel\Database\Connection;
use App\Kernel\Http\Request;
use App\Kernel\Http\RequestContext;
use App\Kernel\Session\Session;
use App\Support\Clock;
use App\Support\DbTime;

/**
 * Remembers where a visitor came from (UTM tags and the referring site) on their first page of a session, and writes it next to the
 * account when they sign up. The session is the only place it lives (the session cookie is a necessary one); the browser never gets a
 * persistent tracking cookie unless the visitor accepted all cookies, and nothing leaves the server. Only the referrer's host is kept,
 * never its path or query.
 */
final class FirstTouch
{
    public const SESSION_KEY = 'analytics.first_touch';

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly RequestContext $context,
    ) {
    }

    /**
     * Take what this request tells about the origin, unless the session already has it.
     *
     * @return array{utm_source: string, utm_medium: string, utm_campaign: string, referrer: string, landing: string, visitor_id: string}|null
     *   what was stored now; null when the session already had its first touch
     */
    public function capture(Session $session, Request $request): ?array
    {
        if (is_array($session->get(self::SESSION_KEY))) {
            return null;
        }
        $touch = [
            'utm_source' => self::tag($request->query['utm_source'] ?? null, 80),
            'utm_medium' => self::tag($request->query['utm_medium'] ?? null, 80),
            'utm_campaign' => self::tag($request->query['utm_campaign'] ?? null, 120),
            'referrer' => self::referrerHost($request->header('referer'), $request->host()),
            'landing' => mb_substr($request->path, 0, 200),
            'visitor_id' => Analytics::newVisitorId(),
        ];
        $session->set(self::SESSION_KEY, $touch);

        return $touch;
    }

    /**
     * Write the session's first touch next to a new account (once; a second call changes nothing).
     */
    public function attribute(int $userId): void
    {
        $touch = $this->context->session()?->get(self::SESSION_KEY);
        if (!is_array($touch)) {
            return;
        }
        $text = static fn (string $key): ?string => is_string($touch[$key] ?? null) && $touch[$key] !== '' ? $touch[$key] : null;
        $this->db->execute(
            'INSERT IGNORE INTO user_attribution (user_id, utm_source, utm_medium, utm_campaign, referrer, landing, visitor_id, first_seen_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$userId, $text('utm_source'), $text('utm_medium'), $text('utm_campaign'), $text('referrer'), $text('landing'), $text('visitor_id'), DbTime::format($this->clock->now())],
        );
    }

    /**
     * A UTM value: printable characters only, lower-case, bounded. Never trusted as markup.
     */
    public static function tag(mixed $value, int $max): string
    {
        if (!is_string($value)) {
            return '';
        }
        $clean = preg_replace('/[^\p{L}\p{N}._~+-]+/u', '_', mb_strtolower(trim($value)));

        return mb_substr(trim((string) $clean, '_'), 0, $max);
    }

    /**
     * The host of an external referrer; empty for none, or for this site itself.
     */
    public static function referrerHost(?string $referrer, string $ownHost): string
    {
        if ($referrer === null || $referrer === '') {
            return '';
        }
        $host = parse_url($referrer, PHP_URL_HOST);
        if (!is_string($host) || $host === '' || strcasecmp($host, explode(':', $ownHost)[0]) === 0) {
            return '';
        }

        return mb_substr(preg_replace('/^www\./i', '', mb_strtolower($host)) ?? '', 0, 120);
    }
}
