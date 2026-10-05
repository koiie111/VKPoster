<?php

declare(strict_types=1);

namespace App\Domain\Notification;

use App\Kernel\Config;
use App\Kernel\Database\Connection;
use App\Kernel\Security\Signer;
use App\Support\Clock;
use App\Support\DbTime;

/**
 * The separate consent to receive news and offers by email (`users.marketing_opt_in_at`) and the way out of it (`marketing_unsubscribed_at`).
 * Mail about the service itself (confirmations, payments, problems) is not marketing and needs neither. The unsubscribe link in a campaign is
 * signed for one person: following it works without signing in, and cannot be forged for somebody else.
 */
final class MarketingConsent
{
    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly Signer $signer,
        private readonly Config $config,
    ) {
    }

    public function isSubscribed(int $userId): bool
    {
        $row = $this->db->select('SELECT marketing_opt_in_at AS i, marketing_unsubscribed_at AS u FROM users WHERE id = ?', [$userId])[0] ?? null;

        return $row !== null && $row['i'] !== null && $row['u'] === null;
    }

    public function subscribe(int $userId): void
    {
        $this->db->execute('UPDATE users SET marketing_opt_in_at = ?, marketing_unsubscribed_at = NULL WHERE id = ?', [DbTime::format($this->clock->now()), $userId]);
    }

    public function unsubscribe(int $userId, ?int $campaignId = null): void
    {
        $now = DbTime::format($this->clock->now());
        $this->db->execute('UPDATE users SET marketing_unsubscribed_at = ? WHERE id = ? AND marketing_unsubscribed_at IS NULL', [$now, $userId]);
        if ($campaignId !== null) {
            $this->db->execute('UPDATE email_campaign_recipients SET unsubscribed_at = ? WHERE campaign_id = ? AND user_id = ? AND unsubscribed_at IS NULL', [$now, $campaignId, $userId]);
        }
    }

    /**
     * The absolute link that unsubscribes one person (from one campaign, when given).
     */
    public function link(int $userId, ?int $campaignId = null): string
    {
        $path = '/unsubscribe/' . $userId . ($campaignId === null ? '' : '?c=' . $campaignId);

        return rtrim($this->config->string('app.url'), '/') . $this->signer->signUrl($path);
    }

    /**
     * Check a request to an unsubscribe link (path and query as received) and say whom and what it is about.
     *
     * @param array<string, mixed> $query
     * @return array{user: int, campaign: ?int}|null null when the signature does not match
     */
    public function verify(string $path, array $query): ?array
    {
        if (preg_match('#^/unsubscribe/(\d{1,12})$#', $path, $m) !== 1) {
            return null;
        }
        $signature = $query['signature'] ?? null;
        $campaign = $query['c'] ?? null;
        if (!is_string($signature) || ($campaign !== null && (!is_string($campaign) || !ctype_digit($campaign)))) {
            return null;
        }
        $url = $path . ($campaign === null ? '' : '?c=' . $campaign) . ($campaign === null ? '?' : '&') . 'signature=' . rawurlencode($signature);

        return $this->signer->verifyUrl($url) ? ['user' => (int) $m[1], 'campaign' => $campaign === null ? null : (int) $campaign] : null;
    }
}
