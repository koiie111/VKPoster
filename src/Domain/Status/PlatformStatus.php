<?php

declare(strict_types=1);

namespace App\Domain\Status;

use App\Domain\Settings\Settings;
use App\Integrations\Social\Contracts\Platform;
use App\Integrations\Social\PlatformRegistry;
use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;
use Redis;
use Throwable;

/**
 * Tells whether a social network is having trouble, from two sources:
 *
 * 1. Our own publishing journal. A platform is `degraded` when, in the last 15 minutes, at least 3 attempts failed because of the platform
 *    itself (`temporary`, `rate_limited` or a lost answer) on at least 2 different channels and no more than half of the attempts succeeded.
 *    One broken channel (a revoked token) never counts: that is the channel's problem, not the network's.
 * 2. The owner: a platform switched off in the admin area is `maintenance`, and a written notice (`status.notices`) overrides the text.
 *
 * The result is cached in Redis for a minute because every page of the application shows the banner; `refresh()` drops the cache after an admin change.
 */
final class PlatformStatus
{
    private const CACHE_KEY = 'status:platforms:v1';
    private const CACHE_SECONDS = 60;
    private const WINDOW_MINUTES = 15;
    private const MIN_FAILURES = 3;
    private const MIN_CHANNELS = 2;

    public function __construct(
        private readonly Connection $db,
        private readonly Redis $redis,
        private readonly Clock $clock,
        private readonly Settings $settings,
        private readonly PlatformRegistry $registry,
    ) {
    }

    /**
     * Every platform the service offers, with its state.
     *
     * @return list<PlatformHealth>
     */
    public function all(): array
    {
        $cached = $this->cached();
        if ($cached === null) {
            $cached = $this->compute();
            $this->store($cached);
        }
        $result = [];
        foreach ($cached as $row) {
            $platform = Platform::tryFrom($row['platform']);
            if ($platform !== null) {
                $result[] = new PlatformHealth($platform, $row['state'], $row['notice']);
            }
        }

        return $result;
    }

    /**
     * Only the platforms with a problem.
     *
     * @return list<PlatformHealth>
     */
    public function problems(): array
    {
        return array_values(array_filter($this->all(), static fn (PlatformHealth $h): bool => $h->isProblem()));
    }

    public function refresh(): void
    {
        try {
            $this->redis->del(self::CACHE_KEY);
        } catch (Throwable) {
            // The cache is an optimisation only.
        }
    }

    /**
     * @return list<array{platform: string, state: string, notice: string|null}>
     */
    private function compute(): array
    {
        $since = DbTime::format($this->clock->now()->modify('-' . self::WINDOW_MINUTES . ' minutes'));
        $stats = [];
        foreach ($this->db->select(
            'SELECT c.platform AS platform, '
            . 'SUM(a.outcome = \'sent\') AS ok, '
            . 'SUM(a.outcome IN (\'temporary\', \'rate_limited\', \'unknown_outcome\', \'unknown\')) AS bad, '
            . 'COUNT(DISTINCT CASE WHEN a.outcome IN (\'temporary\', \'rate_limited\', \'unknown_outcome\', \'unknown\') THEN p.channel_id END) AS channels '
            . 'FROM publication_attempts a JOIN publications p ON p.id = a.publication_id JOIN channels c ON c.id = p.channel_id '
            . 'WHERE a.finished_at >= ? GROUP BY c.platform',
            [$since],
        ) as $row) {
            $stats[(string) $row['platform']] = ['ok' => (int) $row['ok'], 'bad' => (int) $row['bad'], 'channels' => (int) $row['channels']];
        }
        $off = array_values(array_filter((array) $this->settings->get('platforms.off', []), 'is_string'));
        $notices = (array) $this->settings->get('status.notices', []);

        $result = [];
        foreach ($this->registry->configured() as $platform) {
            if ($platform === Platform::Fake) {
                continue;
            }
            $label = $platform->label();
            $manual = isset($notices[$platform->value]) && is_string($notices[$platform->value]) ? trim($notices[$platform->value]) : '';
            $stat = $stats[$platform->value] ?? ['ok' => 0, 'bad' => 0, 'channels' => 0];
            if (in_array($platform->value, $off, true)) {
                $state = PlatformHealth::MAINTENANCE;
                $notice = $manual !== '' ? $manual : $label . ' временно отключён: публикации в эту сеть приостановлены. Мы сообщим, когда всё заработает.';
            } elseif ($manual !== '') {
                $state = PlatformHealth::MAINTENANCE;
                $notice = $manual;
            } elseif ($stat['bad'] >= self::MIN_FAILURES && $stat['channels'] >= self::MIN_CHANNELS && $stat['ok'] * 2 <= $stat['bad'] + $stat['ok']) {
                $state = PlatformHealth::DEGRADED;
                $notice = $label . ' сейчас отвечает с перебоями. Публикации могут задерживаться: мы повторим попытки сами, ничего делать не нужно.';
            } else {
                $state = PlatformHealth::OK;
                $notice = null;
            }
            $result[] = ['platform' => $platform->value, 'state' => $state, 'notice' => $notice];
        }

        return $result;
    }

    /**
     * @return list<array{platform: string, state: string, notice: string|null}>|null
     */
    private function cached(): ?array
    {
        try {
            $raw = $this->redis->get(self::CACHE_KEY);
        } catch (Throwable) {
            return null;
        }
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            return null;
        }
        $rows = [];
        foreach ($decoded as $row) {
            if (is_array($row) && is_string($row['platform'] ?? null) && is_string($row['state'] ?? null)) {
                $rows[] = ['platform' => $row['platform'], 'state' => $row['state'], 'notice' => is_string($row['notice'] ?? null) ? $row['notice'] : null];
            }
        }

        return $rows;
    }

    /**
     * @param list<array{platform: string, state: string, notice: string|null}> $rows
     */
    private function store(array $rows): void
    {
        try {
            $this->redis->setex(self::CACHE_KEY, self::CACHE_SECONDS, json_encode($rows, JSON_THROW_ON_ERROR));
        } catch (Throwable) {
            // The cache is an optimisation only.
        }
    }
}
