<?php

declare(strict_types=1);

namespace App\Domain\Campaign;

use App\Domain\Notification\MarketingConsent;
use App\Domain\Settings\Settings;
use App\Kernel\Database\Connection;
use App\Kernel\Mail\MailMessage;
use App\Kernel\Mail\Mailer;
use App\Kernel\Queue\Queue;
use App\Kernel\View\View;
use App\Support\Clock;
use App\Support\DbTime;
use App\Support\Markdown;
use Symfony\Component\Uid\Ulid;

/**
 * Email campaigns of the owner: a segment of people, a text, a test send, and a sending that goes through the queue at a limited speed.
 *
 * Only people who agreed to news and offers and have not unsubscribed are ever in a segment (the base condition cannot be switched off).
 * Starting a campaign fixes its recipients in a table; every message carries `List-Unsubscribe` (one click, RFC 8058) and an unsubscribe link
 * in the footer. The sending speed is a setting (`campaigns.per_minute`), so a big list does not hit the mail server all at once.
 */
final class Campaigns
{
    public const DEFAULT_PER_MINUTE = 60;

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly Queue $queue,
        private readonly MarketingConsent $consent,
        private readonly Mailer $mailer,
        private readonly View $view,
        private readonly Settings $settings,
    ) {
    }

    /**
     * @param array<string, mixed> $segment
     * @return array{plans: list<string>, platforms: list<string>, activity: string}
     */
    public static function cleanSegment(array $segment): array
    {
        $list = static fn (mixed $v): array => is_array($v) ? array_values(array_filter(array_map('strval', $v), static fn (string $s): bool => preg_match('/^[a-z0-9_]{1,32}$/', $s) === 1)) : [];
        $activity = (string) ($segment['activity'] ?? 'any');

        return [
            'plans' => $list($segment['plans'] ?? []),
            'platforms' => $list($segment['platforms'] ?? []),
            'activity' => in_array($activity, ['any', 'active', 'inactive'], true) ? $activity : 'any',
        ];
    }

    /**
     * @param array<string, mixed> $segment
     * @return array{0: string, 1: list<int|string>} WHERE of a query over `users u`
     */
    private function where(array $segment): array
    {
        $segment = self::cleanSegment($segment);
        $where = "u.marketing_opt_in_at IS NOT NULL AND u.marketing_unsubscribed_at IS NULL AND u.email IS NOT NULL AND u.email_verified_at IS NOT NULL AND u.status = 'active'";
        $bindings = [];
        if ($segment['plans'] !== []) {
            $where .= ' AND EXISTS (SELECT 1 FROM workspaces w JOIN subscriptions s ON s.workspace_id = w.id JOIN plans p ON p.id = s.plan_id WHERE w.owner_id = u.id AND p.code IN (' . implode(',', array_fill(0, count($segment['plans']), '?')) . '))';
            array_push($bindings, ...$segment['plans']);
        }
        if ($segment['platforms'] !== []) {
            $where .= ' AND EXISTS (SELECT 1 FROM channels c JOIN workspaces w ON w.id = c.workspace_id WHERE w.owner_id = u.id AND c.platform IN (' . implode(',', array_fill(0, count($segment['platforms']), '?')) . '))';
            array_push($bindings, ...$segment['platforms']);
        }
        $since = $this->clock->now()->modify('-30 days')->format('Y-m-d');
        if ($segment['activity'] === 'active') {
            $where .= ' AND EXISTS (SELECT 1 FROM user_activity_days d WHERE d.user_id = u.id AND d.day >= ?)';
            $bindings[] = $since;
        } elseif ($segment['activity'] === 'inactive') {
            $where .= ' AND NOT EXISTS (SELECT 1 FROM user_activity_days d WHERE d.user_id = u.id AND d.day >= ?)';
            $bindings[] = $since;
        }

        return [$where, $bindings];
    }

    /**
     * How many people a segment reaches right now.
     *
     * @param array<string, mixed> $segment
     */
    public function count(array $segment): int
    {
        [$where, $bindings] = $this->where($segment);

        return (int) ($this->db->select('SELECT COUNT(*) AS c FROM users u WHERE ' . $where, $bindings)[0]['c'] ?? 0);
    }

    /**
     * @param array<string, mixed> $segment
     */
    public function create(string $name, string $subject, string $body, array $segment, ?int $actorId): string
    {
        $publicId = (string) new Ulid();
        $this->db->table('email_campaigns')->insert([
            'public_id' => $publicId,
            'name' => mb_substr($name, 0, 150),
            'subject' => mb_substr($subject, 0, 200),
            'body_md' => $body,
            'segment_json' => json_encode(self::cleanSegment($segment), JSON_THROW_ON_ERROR),
            'status' => 'draft',
            'created_by' => $actorId,
            'created_at' => DbTime::format($this->clock->now()),
        ]);

        return $publicId;
    }

    /**
     * Change a draft. A campaign that was started is not edited any more.
     *
     * @param array<string, mixed> $segment
     */
    public function update(string $publicId, string $name, string $subject, string $body, array $segment): bool
    {
        return $this->db->execute(
            "UPDATE email_campaigns SET name = ?, subject = ?, body_md = ?, segment_json = ? WHERE public_id = ? AND status = 'draft'",
            [mb_substr($name, 0, 150), mb_substr($subject, 0, 200), $body, json_encode(self::cleanSegment($segment), JSON_THROW_ON_ERROR), $publicId],
        ) === 1;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $publicId): ?array
    {
        $rows = $this->db->select('SELECT * FROM email_campaigns WHERE public_id = ?', [strtoupper($publicId)]);

        return $rows === [] ? null : $this->row($rows[0]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return array_map($this->row(...), $this->db->select('SELECT * FROM email_campaigns ORDER BY id DESC LIMIT 100'));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function row(array $row): array
    {
        $segment = json_decode((string) $row['segment_json'], true);
        $row['segment'] = self::cleanSegment(is_array($segment) ? $segment : []);
        foreach (['created_at', 'started_at', 'finished_at'] as $key) {
            $row[$key] = DbTime::parse($row[$key]);
        }

        return $row;
    }

    /**
     * Counts of a campaign: queued, sent, failed, skipped and how many people unsubscribed because of it.
     *
     * @return array{queued: int, sent: int, failed: int, skipped: int, unsubscribed: int, total: int}
     */
    public function stats(int $campaignId): array
    {
        $by = [];
        foreach ($this->db->select('SELECT status, COUNT(*) AS c FROM email_campaign_recipients WHERE campaign_id = ? GROUP BY status', [$campaignId]) as $row) {
            $by[(string) $row['status']] = (int) $row['c'];
        }

        return [
            'queued' => $by['queued'] ?? 0,
            'sent' => $by['sent'] ?? 0,
            'failed' => $by['failed'] ?? 0,
            'skipped' => $by['skipped'] ?? 0,
            'unsubscribed' => (int) ($this->db->select('SELECT COUNT(*) AS c FROM email_campaign_recipients WHERE campaign_id = ? AND unsubscribed_at IS NOT NULL', [$campaignId])[0]['c'] ?? 0),
            'total' => array_sum($by),
        ];
    }

    /**
     * Fix the recipients and start sending. Returns how many people will get the email (0 means nothing was started).
     */
    public function start(string $publicId): int
    {
        $campaign = $this->find($publicId);
        if ($campaign === null || $campaign['status'] !== 'draft') {
            return 0;
        }
        [$where, $bindings] = $this->where($campaign['segment']);
        $count = $this->db->transaction(function (Connection $db) use ($campaign, $where, $bindings): int {
            $db->execute(
                'INSERT IGNORE INTO email_campaign_recipients (campaign_id, user_id, email, status) SELECT ?, u.id, u.email, \'queued\' FROM users u WHERE ' . $where,
                [$campaign['id'], ...$bindings],
            );
            $total = (int) ($db->select('SELECT COUNT(*) AS c FROM email_campaign_recipients WHERE campaign_id = ?', [$campaign['id']])[0]['c'] ?? 0);
            $db->execute(
                'UPDATE email_campaigns SET status = ?, total = ?, started_at = ? WHERE id = ?',
                [$total === 0 ? 'done' : 'sending', $total, DbTime::format($this->clock->now()), $campaign['id']],
            );

            return $total;
        });
        if ($count > 0) {
            $this->queue->dispatch(new SendCampaignBatchJob((int) $campaign['id']));
        }

        return $count;
    }

    public function cancel(string $publicId): void
    {
        $this->db->execute(
            "UPDATE email_campaigns SET status = 'cancelled', finished_at = ? WHERE public_id = ? AND status = 'sending'",
            [DbTime::format($this->clock->now()), strtoupper($publicId)],
        );
    }

    public function perMinute(): int
    {
        $value = $this->settings->get('campaigns.per_minute');

        return is_int($value) && $value >= 1 && $value <= 6000 ? $value : self::DEFAULT_PER_MINUTE;
    }

    /**
     * Send the next batch of a running campaign (at most `perMinute()` emails). Returns how many are still waiting.
     */
    public function sendBatch(int $campaignId): int
    {
        $rows = $this->db->select('SELECT * FROM email_campaigns WHERE id = ?', [$campaignId]);
        if ($rows === [] || $rows[0]['status'] !== 'sending') {
            return 0;
        }
        $campaign = $this->row($rows[0]);
        $batch = $this->db->select(
            'SELECT r.id, r.user_id, r.email, u.name, u.marketing_opt_in_at, u.marketing_unsubscribed_at FROM email_campaign_recipients r LEFT JOIN users u ON u.id = r.user_id '
            . "WHERE r.campaign_id = ? AND r.status = 'queued' ORDER BY r.id LIMIT " . $this->perMinute(),
            [$campaignId],
        );
        foreach ($batch as $recipient) {
            $now = DbTime::format($this->clock->now());
            // Somebody who left between the start and now is not written to.
            if ($recipient['user_id'] === null || $recipient['marketing_opt_in_at'] === null || $recipient['marketing_unsubscribed_at'] !== null) {
                $this->db->execute("UPDATE email_campaign_recipients SET status = 'skipped', error = 'unsubscribed' WHERE id = ?", [$recipient['id']]);
                continue;
            }
            try {
                $this->deliver((string) $recipient['email'], (string) $recipient['name'], $campaign['subject'], $campaign['body_md'], (int) $recipient['user_id'], $campaignId);
                $this->db->execute("UPDATE email_campaign_recipients SET status = 'sent', sent_at = ? WHERE id = ?", [$now, $recipient['id']]);
            } catch (\Throwable $e) {
                $this->db->execute("UPDATE email_campaign_recipients SET status = 'failed', error = ? WHERE id = ?", [mb_substr($e::class, 0, 255), $recipient['id']]);
            }
        }
        $left = (int) ($this->db->select("SELECT COUNT(*) AS c FROM email_campaign_recipients WHERE campaign_id = ? AND status = 'queued'", [$campaignId])[0]['c'] ?? 0);
        if ($left === 0) {
            $this->db->execute("UPDATE email_campaigns SET status = 'done', finished_at = ? WHERE id = ? AND status = 'sending'", [DbTime::format($this->clock->now()), $campaignId]);
        }

        return $left;
    }

    /**
     * Send the text to one address (a test from the editor). The unsubscribe link is the one for the person if they are known.
     */
    public function test(string $toEmail, string $name, string $subject, string $body, ?int $userId): void
    {
        $this->deliver($toEmail, $name, '[Тест] ' . $subject, $body, $userId, null);
    }

    /**
     * How the email looks: the HTML and the plain text, for the preview and for sending.
     *
     * @return array{html: string, text: string}
     */
    public function render(string $subject, string $body, string $name, string $unsubscribe): array
    {
        $personal = str_replace('{name}', $name === '' ? 'друг' : $name, $body);

        return [
            'html' => $this->view->render('emails/campaign.html.twig', ['subject' => $subject, 'body_html' => Markdown::toHtml($personal), 'unsubscribe' => $unsubscribe]),
            'text' => $personal . "\n\n---\nОтписаться от новостей: " . $unsubscribe . "\n",
        ];
    }

    private function deliver(string $email, string $name, string $subject, string $body, ?int $userId, ?int $campaignId): void
    {
        $link = $userId === null ? '' : $this->consent->link($userId, $campaignId);
        $mail = $this->render($subject, $body, $name, $link === '' ? '#' : $link);
        $headers = $link === '' ? [] : ['List-Unsubscribe' => '<' . $link . '>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'];
        $this->mailer->send(new MailMessage($email, $subject, $mail['html'], $mail['text'], $headers));
    }
}
