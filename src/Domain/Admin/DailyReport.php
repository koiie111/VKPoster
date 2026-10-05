<?php

declare(strict_types=1);

namespace App\Domain\Admin;

use App\Domain\Analytics\MetricsAggregator;
use App\Domain\Analytics\MetricsReader;
use App\Domain\Analytics\ReportFilters;
use App\Domain\Notification\MailComposer;
use App\Domain\Notification\SendTelegramNotificationJob;
use App\Domain\Notification\TelegramLinks;
use App\Domain\Settings\Settings;
use App\Domain\User\User;
use App\Domain\User\UserRepository;
use App\Kernel\Database\Connection;
use App\Kernel\Queue\Queue;
use App\Support\Clock;
use App\Support\DbTime;
use App\Support\Money;
use DateTimeZone;

/**
 * The owner's daily report: yesterday's revenue, sign-ups, payments, failed publications and the state of the queue, sent once a day at the
 * hour the owner chose, by email and/or to their private Telegram chat with the shared bot. `tick()` runs every hour from the scheduler and
 * sends at most one report per local day, so a restart or a repeated run never sends it twice.
 *
 * Settings (`app_settings`): `report.enabled`, `report.hour` (0 to 23 in `report.timezone`), `report.timezone`, `report.email`, `report.telegram`,
 * `report.recipients` (user ids of staff; empty means every owner account), `report.last_sent` (the local date of the last report).
 */
final class DailyReport
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Clock $clock,
        private readonly Connection $db,
        private readonly MetricsAggregator $aggregator,
        private readonly MetricsReader $reader,
        private readonly MailComposer $mail,
        private readonly Queue $queue,
        private readonly TelegramLinks $links,
        private readonly StaffAccess $staff,
        private readonly UserRepository $users,
    ) {
    }

    /**
     * @return array{enabled: bool, hour: int, timezone: string, email: bool, telegram: bool, recipients: list<int>}
     */
    public function config(): array
    {
        $recipients = $this->settings->get('report.recipients', []);
        $zone = $this->settings->get('report.timezone');
        $hour = $this->settings->get('report.hour');

        return [
            'enabled' => $this->settings->get('report.enabled') === true,
            'hour' => is_int($hour) ? max(0, min(23, $hour)) : 9,
            'timezone' => is_string($zone) && in_array($zone, timezone_identifiers_list(), true) ? $zone : 'Europe/Moscow',
            'email' => $this->settings->get('report.email') !== false,
            'telegram' => $this->settings->get('report.telegram') !== false,
            'recipients' => array_values(array_filter(is_array($recipients) ? $recipients : [], 'is_int')),
        ];
    }

    /**
     * @param array<string, mixed> $input form fields: enabled, hour, timezone, email, telegram
     * @return list<string> problems in the owner's words; empty when saved
     */
    public function save(array $input, ?int $actorId): array
    {
        $hour = (string) ($input['hour'] ?? '9');
        $zone = (string) ($input['timezone'] ?? 'Europe/Moscow');
        $errors = [];
        if (preg_match('/^\d{1,2}$/', $hour) !== 1 || (int) $hour > 23) {
            $errors[] = 'Час отправки — число от 0 до 23.';
        }
        if (!in_array($zone, timezone_identifiers_list(), true)) {
            $errors[] = 'Выберите часовой пояс из списка.';
        }
        if ($errors !== []) {
            return $errors;
        }
        $this->settings->set('report.enabled', ($input['enabled'] ?? '') === '1', $actorId);
        $this->settings->set('report.hour', (int) $hour, $actorId);
        $this->settings->set('report.timezone', $zone, $actorId);
        $this->settings->set('report.email', ($input['email'] ?? '') === '1', $actorId);
        $this->settings->set('report.telegram', ($input['telegram'] ?? '') === '1', $actorId);

        return [];
    }

    /**
     * Send the report if it is time and today's has not gone out. Returns how many people it was sent to.
     */
    public function tick(): int
    {
        $config = $this->config();
        if (!$config['enabled']) {
            return 0;
        }
        $local = $this->clock->now()->setTimezone(new DateTimeZone($config['timezone']));
        if ((int) $local->format('G') !== $config['hour'] || $this->settings->get('report.last_sent') === $local->format('Y-m-d')) {
            return 0;
        }
        // Mark first: a crash in the middle must not turn into a second report an hour later.
        $this->settings->set('report.last_sent', $local->format('Y-m-d'), null);

        return $this->send($this->recipients());
    }

    /**
     * Send the report now to given people (the "send me a test" button).
     *
     * @param list<User> $people
     */
    public function send(array $people): int
    {
        $config = $this->config();
        $this->aggregator->recent(2);
        $text = $this->text();
        $sent = 0;
        foreach ($people as $person) {
            $reached = false;
            if ($config['email'] && $person->email !== null) {
                $this->mail->send($person->email, 'Отчёт за вчера', 'daily_report', ['body' => $text]);
                $reached = true;
            }
            $chat = $config['telegram'] ? $this->links->linkOf($person->id) : null;
            if ($chat !== null) {
                $this->queue->dispatch(new SendTelegramNotificationJob($chat['chat_id'], $text));
                $reached = true;
            }
            $sent += $reached ? 1 : 0;
        }

        return $sent;
    }

    /**
     * Staff who get the report: the chosen ones, or every owner account when none is chosen.
     *
     * @return list<User>
     */
    private function recipients(): array
    {
        $ids = $this->config()['recipients'];
        if ($ids === []) {
            $ids = array_map(static fn (array $r): int => (int) $r['id'], $this->db->select('SELECT id FROM users WHERE is_superadmin = 1'));
        }
        $users = [];
        foreach ($ids as $id) {
            $user = $this->users->find($id);
            if ($user !== null && $this->staff->isStaff($user)) {
                $users[] = $user;
            }
        }

        return $users;
    }

    /**
     * The text of the report for the last full UTC day.
     */
    public function text(): string
    {
        $today = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0);
        $day = $today->modify('-1 day');
        $filters = new ReportFilters($day, $day, '', '', '', 'RUB');
        $now = $this->reader->kpis($filters)['current'];
        $failed = (int) ($this->db->select(
            "SELECT COUNT(*) AS c FROM publications WHERE status IN ('failed', 'unknown') AND updated_at >= ? AND updated_at < ?",
            [DbTime::format($day), DbTime::format($today)],
        )[0]['c'] ?? 0);
        $queue = (int) ($this->db->select('SELECT COUNT(*) AS c FROM jobs')[0]['c'] ?? 0);
        $failedJobs = (int) ($this->db->select('SELECT COUNT(*) AS c FROM failed_jobs')[0]['c'] ?? 0);
        $payments = (int) ($this->db->select("SELECT COUNT(*) AS c FROM payments WHERE status IN ('succeeded', 'refunded') AND created_at >= ? AND created_at < ?", [DbTime::format($day), DbTime::format($today)])[0]['c'] ?? 0);
        $open = (int) ($this->db->select("SELECT COUNT(*) AS c FROM support_tickets WHERE status = 'open'")[0]['c'] ?? 0);

        return implode("\n", [
            'Отчёт за ' . $day->format('d.m.Y') . ' (UTC)',
            '',
            'Выручка: ' . Money::format((int) $now['revenue']),
            'Оплат: ' . $payments,
            'MRR: ' . Money::format((int) $now['mrr']) . ', платящих: ' . $now['paying'],
            'Регистраций: ' . $now['signups'] . ', активировались: ' . $now['activated'],
            'Публикаций вышло: ' . $now['pubs_sent'] . ', не вышло: ' . $failed,
            'В очереди задач: ' . $queue . ', упавших: ' . $failedJobs,
            'Обращений ждёт ответа: ' . $open,
        ]);
    }
}
