<?php

declare(strict_types=1);

namespace App\Domain\Link;

use App\Domain\Settings\Settings;

/**
 * Decides which addresses may go into a short link or a post. Only `http` and `https` addresses with a real host pass: no
 * `javascript:` or `data:`, no login and password in the address, no internal hosts, and nothing from the blocklist the owner keeps
 * in the admin area (a domain and all its subdomains). The same check runs again when somebody follows a link, so a domain that was
 * added to the list later stops working at once.
 */
final class UrlGuard
{
    public const MAX_LENGTH = 2000;
    public const SETTING = 'links.blocked_domains';

    public function __construct(private readonly Settings $settings, private readonly string $shortDomain = '')
    {
    }

    /**
     * What is wrong with this address for a short link, in Russian, or null when it is fine.
     */
    public function problem(string $url): ?string
    {
        if ($url === '' || strlen($url) > self::MAX_LENGTH || preg_match('/[\x00-\x20\x7F\\\\]/', $url) === 1) {
            return 'Адрес пустой, слишком длинный или содержит пробелы.';
        }
        $parts = parse_url($url);
        $scheme = is_array($parts) ? strtolower($parts['scheme'] ?? '') : '';
        if (!in_array($scheme, ['http', 'https'], true)) {
            return 'Ссылка должна начинаться с http:// или https://.';
        }
        $host = strtolower(rtrim($parts['host'] ?? '', '.'));
        if ($host === '' || isset($parts['user']) || isset($parts['pass'])) {
            return 'В адресе не должно быть логина и пароля, а домен обязателен.';
        }
        if ($this->isInternalHost($host)) {
            return 'Ссылки на внутренние адреса сократить нельзя.';
        }
        if ($this->shortDomain !== '' && $host === $this->shortDomain) {
            return 'Это уже короткая ссылка.';
        }

        return $this->isBlocked($url) ? 'Этот сайт в списке запрещённых.' : null;
    }

    /** True when the address belongs to a blocked domain (or one of its subdomains). */
    public function isBlocked(string $url): bool
    {
        $host = strtolower(rtrim((string) (parse_url($url, PHP_URL_HOST) ?? ''), '.'));
        if ($host === '') {
            return false;
        }
        foreach ($this->blocked() as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function blocked(): array
    {
        $list = $this->settings->get(self::SETTING, []);

        return is_array($list) ? array_values(array_filter($list, static fn (mixed $d): bool => is_string($d) && $d !== '')) : [];
    }

    /**
     * Turns what the owner typed (one domain or address per line) into a clean list of domains.
     *
     * @return list<string>
     */
    public static function parseList(string $text): array
    {
        $domains = [];
        $entries = preg_split('/[\s,;]+/', mb_strtolower($text));
        foreach ($entries === false ? [] : $entries as $entry) {
            $host = preg_match('~^[a-z]+://~', $entry) === 1 ? (string) parse_url($entry, PHP_URL_HOST) : explode('/', $entry)[0];
            $host = trim(preg_replace('/^www\./', '', $host) ?? '', '.');
            if ($host !== '' && preg_match('/^[\p{L}\p{N}.-]+$/u', $host) === 1 && str_contains($host, '.')) {
                $domains[$host] = $host;
            }
        }

        return array_slice(array_values($domains), 0, 2000);
    }

    private function isInternalHost(string $host): bool
    {
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal') || (!str_contains($host, '.') && !str_contains($host, ':'))) {
            return true;
        }
        $ip = trim($host, '[]');
        if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        }

        return false;
    }
}
