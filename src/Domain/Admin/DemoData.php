<?php

declare(strict_types=1);

namespace App\Domain\Admin;

use App\Domain\Analytics\MetricsAggregator;
use App\Domain\Billing\Ledger;
use App\Domain\Billing\PaymentRepository;
use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\Uid\Ulid;

/**
 * Local demo data for looking at the dashboard and for load-testing the aggregation: people, workspaces, channels, publications, trials,
 * subscriptions, invoices, payments with renewals, upgrades, churn and refunds, activity and visits, spread over the last months with a
 * plausible shape (more sign-ups lately, a share who connect a channel, a share who pay, a few who leave).
 *
 * Everything is tied to the address domain `demo.ezposter.local`, so `purge()` removes exactly what this class made (the rest of the
 * database is untouched; foreign keys take the dependants with the people). The random generator is seeded: the same arguments produce the
 * same data. Writes straight to the tables and never calls a payment provider or a social network. The command refuses to run outside local.
 */
final class DemoData
{
    public const DOMAIN = 'demo.ezposter.local';

    private const SOURCES = ['direct' => 35, 'vk-ads' => 20, 'tg-ads' => 15, 'yandex' => 10, 'newsletter' => 8, 'blog' => 7, 'partner' => 5];
    private const PLANS = ['start' => 55, 'pro' => 35, 'agency' => 10];
    private const PLATFORMS = ['telegram' => 55, 'vk' => 35, 'max' => 10];
    private const ERRORS = [['rate_limited', 'rate_limited', 'Слишком много запросов к сети'], ['auth', 'auth', 'Доступ к каналу потерян'], ['permanent', 'permanent', 'Сеть отклонила пост'], ['temporary', 'temporary', 'Сеть не отвечает']];
    private const FIRST = ['Анна', 'Иван', 'Мария', 'Алексей', 'Ольга', 'Дмитрий', 'Елена', 'Сергей', 'Наталья', 'Андрей', 'Татьяна', 'Максим', 'Юлия', 'Павел', 'Ирина'];
    private const LAST = ['Иванов', 'Петров', 'Смирнов', 'Кузнецов', 'Попов', 'Соколов', 'Лебедев', 'Новиков', 'Морозов', 'Волков'];

    private int $invoiceSeq = 0;

    /** @var array<string, array{id: int, month: int, year: int}> */
    private array $plans = [];

    private DateTimeImmutable $moment;

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly PaymentRepository $payments,
        private readonly MetricsAggregator $aggregator,
    ) {
        $this->moment = $this->clock->now();
    }

    /**
     * Remove everything made by `generate()`.
     */
    public function purge(): int
    {
        $ids = array_column($this->db->select('SELECT id FROM users WHERE email LIKE ?', ['%@' . self::DOMAIN]), 'id');
        if ($ids === []) {
            return 0;
        }
        $in = implode(',', array_map('intval', $ids));
        // Rows that have no foreign key to the people: the money journal entries of their payments, audit rows, events, activity.
        $this->db->execute("DELETE e FROM ledger_entries e JOIN payments p ON p.public_id = e.ref_id JOIN workspaces w ON w.id = p.workspace_id WHERE w.owner_id IN ($in)");
        $this->db->execute("DELETE FROM audit_log WHERE workspace_id IN (SELECT id FROM workspaces WHERE owner_id IN ($in)) AND action LIKE 'billing.%'");
        $this->db->execute("DELETE FROM user_activity_days WHERE user_id IN ($in)");
        $this->db->execute("DELETE FROM analytics_events WHERE user_id IN ($in) OR visitor_id LIKE 'DEMO%'");
        $this->db->execute("DELETE FROM users WHERE id IN ($in)");

        return count($ids);
    }

    /**
     * @param int $users how many people to create
     * @param int $days how far back the first of them registered
     * @return array<string, int> what was created
     */
    public function generate(int $users, int $days, int $seed = 2026): array
    {
        mt_srand($seed);
        $now = $this->moment;
        $this->loadPlans();
        $summary = ['users' => 0, 'workspaces' => 0, 'channels' => 0, 'publications' => 0, 'invoices' => 0, 'payments' => 0, 'refunds' => 0, 'visits' => 0];
        $clock = new DemoClock($now);
        $ledger = new Ledger($this->db, $clock);
        $freePlan = (int) ($this->db->select("SELECT id FROM plans WHERE code = 'free'")[0]['id'] ?? 0);

        for ($i = 1; $i <= $users; $i++) {
            // More sign-ups lately: the day is drawn from a distribution that leans towards today.
            $ago = (int) floor($days * (1 - sqrt(mt_rand(0, 10000) / 10000)));
            $registered = $now->modify('-' . $ago . ' days')->setTime(mt_rand(7, 22), mt_rand(0, 59), mt_rand(0, 59));
            $source = $this->pick(self::SOURCES);
            $verified = mt_rand(1, 100) <= 92;
            $name = self::FIRST[array_rand(self::FIRST)] . ' ' . self::LAST[array_rand(self::LAST)];
            $userId = $this->insertUser($i, $name, $registered, $verified);
            $workspaceId = $this->insertWorkspace($userId, $name, $registered);
            $this->db->execute(
                'INSERT INTO user_attribution (user_id, utm_source, referrer, landing, first_seen_at) VALUES (?, ?, ?, ?, ?)',
                [$userId, $source === 'direct' || $source === 'blog' ? null : $source, $source === 'blog' ? 'blog.example.com' : null, '/', DbTime::format($registered)],
            );
            ++$summary['users'];
            ++$summary['workspaces'];

            $hasChannel = $verified && mt_rand(1, 100) <= 62;
            $channels = [];
            if ($hasChannel) {
                $count = mt_rand(1, 100) <= 70 ? 1 : mt_rand(2, 4);
                for ($c = 0; $c < $count; $c++) {
                    $channels[] = $this->insertChannel($workspaceId, $userId, $registered->modify('+' . mt_rand(0, 2) . ' days'), $i . '-' . $c);
                    ++$summary['channels'];
                }
            }
            if ($channels !== [] && mt_rand(1, 100) <= 78) {
                $summary['publications'] += $this->publish($workspaceId, $userId, $channels, $registered->modify('+1 day'));
            }
            $this->activity($userId, $registered, $channels !== []);

            $paid = $this->subscribe($ledger, $clock, $workspaceId, $userId, $freePlan, $registered, $channels !== []);
            $summary['invoices'] += $paid['invoices'];
            $summary['payments'] += $paid['payments'];
            $summary['refunds'] += $paid['refunds'];
        }
        // Visitors who never signed up (the top of the funnel).
        $visits = (int) round($users * 9);
        for ($v = 0; $v < $visits; $v++) {
            $at = $now->modify('-' . mt_rand(0, $days) . ' days')->setTime(mt_rand(0, 23), mt_rand(0, 59));
            $this->db->execute(
                'INSERT INTO analytics_events (name, visitor_id, props_json, occurred_at) VALUES (?, ?, ?, ?)',
                ['visit', 'DEMO' . strtoupper(substr(bin2hex(random_bytes(11)), 0, 22)), json_encode(['source' => $this->pick(self::SOURCES)], JSON_THROW_ON_ERROR), DbTime::format($at)],
            );
            ++$summary['visits'];
        }
        $this->aggregator->range($now->modify('-' . ($days + 35) . ' days'), $now);

        return $summary;
    }

    private function loadPlans(): void
    {
        foreach ($this->db->select("SELECT p.id, p.code, MAX(CASE WHEN pr.period = 'month' THEN pr.amount END) AS month, MAX(CASE WHEN pr.period = 'year' THEN pr.amount END) AS year FROM plans p LEFT JOIN plan_prices pr ON pr.plan_id = p.id AND pr.currency = 'RUB' GROUP BY p.id, p.code") as $row) {
            $this->plans[(string) $row['code']] = ['id' => (int) $row['id'], 'month' => (int) ($row['month'] ?? 0), 'year' => (int) ($row['year'] ?? 0)];
        }
    }

    /**
     * @param array<string, int> $weights
     */
    private function pick(array $weights): string
    {
        $roll = mt_rand(1, array_sum($weights));
        foreach ($weights as $key => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return $key;
            }
        }

        return (string) array_key_first($weights);
    }

    private function insertUser(int $n, string $name, DateTimeImmutable $at, bool $verified): int
    {
        $t = DbTime::format($at);
        $this->db->execute(
            'INSERT INTO users (email, email_verified_at, name, created_at, updated_at, consent_at) VALUES (?, ?, ?, ?, ?, ?)',
            ['u' . $n . '@' . self::DOMAIN, $verified ? DbTime::format($at->modify('+' . mt_rand(1, 600) . ' minutes')) : null, $name, $t, $t, $t],
        );

        return (int) $this->db->lastInsertId();
    }

    private function insertWorkspace(int $userId, string $name, DateTimeImmutable $at): int
    {
        $t = DbTime::format($at);
        $this->db->execute(
            'INSERT INTO workspaces (public_id, owner_id, name, is_personal, created_at, updated_at) VALUES (?, ?, ?, 1, ?, ?)',
            [(string) new Ulid(), $userId, 'Пространство ' . $name, $t, $t],
        );
        $id = (int) $this->db->lastInsertId();
        $this->db->execute('INSERT INTO workspace_members (workspace_id, user_id, public_id, role, joined_at) VALUES (?, ?, ?, ?, ?)', [$id, $userId, (string) new Ulid(), 'owner', $t]);

        return $id;
    }

    /**
     * @return array{id: int, platform: string, title: string}
     */
    private function insertChannel(int $workspaceId, int $userId, DateTimeImmutable $at, string $key): array
    {
        $platform = $this->pick(self::PLATFORMS);
        $roll = mt_rand(1, 100);
        $status = $roll <= 90 ? 'active' : ($roll <= 96 ? 'error' : 'revoked');
        $title = 'Канал ' . $key;
        $t = DbTime::format($at);
        $this->db->execute(
            'INSERT INTO channels (public_id, workspace_id, platform, external_id, mode, title, kind, status, last_health_at, last_error, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [(string) new Ulid(), $workspaceId, $platform, 'demo-' . $key, 'shared_bot', $title, 'channel', $status, $t, $status === 'active' ? null : 'Бот больше не администратор канала', $userId, $t, $t],
        );

        return ['id' => (int) $this->db->lastInsertId(), 'platform' => $platform, 'title' => $title];
    }

    /**
     * @param list<array{id: int, platform: string, title: string}> $channels
     * @return int publications written
     */
    private function publish(int $workspaceId, int $userId, array $channels, DateTimeImmutable $from): int
    {
        $now = $this->moment;
        $perWeek = mt_rand(1, 100) <= 25 ? mt_rand(8, 20) : mt_rand(1, 6);
        $written = 0;
        $weeks = max(1, (int) floor(($now->getTimestamp() - $from->getTimestamp()) / 604800));
        $total = min(60, $perWeek * min($weeks, 8));
        $decay = mt_rand(1, 100) <= 20;
        for ($p = 0; $p < $total; $p++) {
            $span = $now->getTimestamp() - $from->getTimestamp();
            $due = (new DateTimeImmutable('@' . ($from->getTimestamp() + (int) ($span * ($decay ? sqrt(mt_rand(0, 1000) / 1000) * 0.6 : mt_rand(0, 1000) / 1000)))))->setTimezone(new DateTimeZone('UTC'));
            if ($due > $now) {
                continue;
            }
            $created = DbTime::format($due->modify('-' . mt_rand(1, 72) . ' hours'));
            $this->db->execute(
                "INSERT INTO posts (public_id, workspace_id, author_id, status, base_text, scheduled_at, timezone, published_at, created_at, updated_at) VALUES (?, ?, ?, 'published', ?, ?, 'Europe/Moscow', ?, ?, ?)",
                [(string) new Ulid(), $workspaceId, $userId, 'Демо-пост ' . ($p + 1), DbTime::format($due), DbTime::format($due), $created, $created],
            );
            $postId = (int) $this->db->lastInsertId();
            foreach ($channels as $channel) {
                if (mt_rand(1, 100) > 75 && count($channels) > 1) {
                    continue;
                }
                $this->db->execute('INSERT INTO post_variants (post_id, workspace_id, channel_id, platform, channel_name) VALUES (?, ?, ?, ?, ?)', [$postId, $workspaceId, $channel['id'], $channel['platform'], $channel['title']]);
                $variantId = (int) $this->db->lastInsertId();
                $roll = mt_rand(1, 1000);
                $failed = $roll <= 55;
                $unknown = !$failed && $roll <= 62;
                $status = $failed ? 'failed' : ($unknown ? 'unknown' : 'sent');
                $latency = $failed ? 0 : (mt_rand(1, 100) <= 95 ? mt_rand(1, 25) : mt_rand(30, 240));
                $sentAt = $due->modify('+' . $latency . ' seconds');
                $error = $failed ? self::ERRORS[array_rand(self::ERRORS)] : null;
                $t = DbTime::format($due);
                $this->db->execute(
                    'INSERT INTO publications (public_id, workspace_id, post_id, variant_id, channel_id, status, attempt, due_at, run_at, idempotency_key, external_post_id, error_code, error_message, started_at, sent_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        (string) new Ulid(), $workspaceId, $postId, $variantId, $channel['id'], $status, $failed ? 3 : 1, $t, $t, hash('sha256', 'demo' . $variantId),
                        $status === 'sent' ? (string) mt_rand(100, 99999) : null, $error[0] ?? null, $error[2] ?? null, $t, $status === 'sent' ? DbTime::format($sentAt) : null, $created, DbTime::format($sentAt),
                    ],
                );
                $publicationId = (int) $this->db->lastInsertId();
                $this->db->execute(
                    'INSERT INTO publication_attempts (publication_id, workspace_id, attempt, outcome, error_kind, message, started_at, finished_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [$publicationId, $workspaceId, 1, $failed ? $error[1] : ($unknown ? 'unknown' : 'sent'), $failed ? $error[1] : ($unknown ? 'unknown_outcome' : null), $error[2] ?? null, $t, DbTime::format($sentAt)],
                );
                ++$written;
            }
        }

        return $written;
    }

    private function activity(int $userId, DateTimeImmutable $registered, bool $engaged): void
    {
        $now = $this->moment;
        $day = $registered->setTime(0, 0);
        $probability = $engaged ? 0.55 : 0.25;
        for ($d = 0; $day <= $now; $d++, $day = $day->modify('+1 day')) {
            if (mt_rand(0, 1000) / 1000 < $probability * exp(-$d / ($engaged ? 70 : 12)) + ($d === 0 ? 0.5 : 0.0)) {
                $this->db->execute('INSERT IGNORE INTO user_activity_days (user_id, day) VALUES (?, ?)', [$userId, $day->format('Y-m-d')]);
            }
        }
    }

    /**
     * The commercial life of one workspace: trial, first payment, renewals, upgrade, refund, leaving. Writes the subscription row that
     * describes where it ended up.
     *
     * @return array{invoices: int, payments: int, refunds: int}
     */
    private function subscribe(Ledger $ledger, DemoClock $clock, int $workspaceId, int $userId, int $freePlan, DateTimeImmutable $registered, bool $hasChannel): array
    {
        $now = $this->moment;
        $result = ['invoices' => 0, 'payments' => 0, 'refunds' => 0];
        $email = 'u@' . self::DOMAIN;
        $trialAt = $hasChannel && mt_rand(1, 100) <= 85 ? $registered->modify('+' . mt_rand(0, 3) . ' days') : null;
        $plan = 'free';
        $periodStart = null;
        $periodEnd = null;
        $period = null;
        $status = 'active';
        $trialing = false;
        $trialEnds = null;
        $lastFailure = null;
        if ($trialAt !== null && $trialAt <= $now) {
            $this->db->execute('INSERT INTO audit_log (workspace_id, actor_id, action, subject_type, created_at) VALUES (?, NULL, ?, ?, ?)', [$workspaceId, 'billing.trial_started', 'subscription', DbTime::format($trialAt)]);
            $trialEnds = $trialAt->modify('+7 days');
            if ($trialEnds > $now) {
                $trialing = true;
                $plan = 'pro';
                $periodEnd = $trialEnds;
                $periodStart = $trialAt;
            } elseif (mt_rand(1, 100) <= 45) {
                $plan = $this->pick(self::PLANS);
                $period = mt_rand(1, 100) <= 85 ? 'month' : 'year';
                $start = $trialEnds;
                $leaving = false;
                $kind = 'new';
                while ($start <= $now && !$leaving) {
                    $end = $period === 'year' ? $start->modify('+1 year') : $start->modify('+1 month');
                    $price = $this->plans[$plan][$period];
                    $clock->at = $start;
                    $payment = $this->invoice($ledger, $workspaceId, $plan, $period, $kind, $price, $price, $start, $end, $email);
                    $result['invoices']++;
                    $result['payments']++;
                    if (mt_rand(1, 100) <= 3) {
                        $clock->at = $start->modify('+2 days');
                        $paymentRow = $this->payments->findByPublicId($payment) ?? throw new \LogicException('payment');
                        $ledger->recordRefund($paymentRow, $paymentRow->amount, 'refund-' . $payment);
                        $this->db->execute("UPDATE payments SET status = 'refunded', refunded_amount = amount WHERE public_id = ?", [$payment]);
                        $result['refunds']++;
                        $leaving = true;
                        $plan = 'free';
                        $periodStart = $periodEnd = null;
                        break;
                    }
                    $periodStart = $start;
                    $periodEnd = $end;
                    if ($period === 'month' && $plan !== 'agency' && mt_rand(1, 100) <= 4 && $start->modify('+12 days') < $now && $end > $start->modify('+12 days')) {
                        // Upgrade in the middle of the period: pay the difference for the rest, start a new period.
                        $upAt = $start->modify('+12 days');
                        $to = $plan === 'start' ? 'pro' : 'agency';
                        $left = ($end->getTimestamp() - $upAt->getTimestamp()) / max(1, $end->getTimestamp() - $start->getTimestamp());
                        $diff = (int) round(($this->plans[$to]['month'] - $price) * $left);
                        $clock->at = $upAt;
                        $this->invoice($ledger, $workspaceId, $to, 'month', 'upgrade', $diff, $this->plans[$to]['month'], $upAt, $upAt->modify('+1 month'), $email);
                        ++$result['invoices'];
                        ++$result['payments'];
                        $plan = $to;
                        $periodStart = $upAt;
                        $periodEnd = $upAt->modify('+1 month');
                        $start = $periodEnd;
                        $kind = 'renewal';
                        continue;
                    }
                    $start = $end;
                    $kind = 'renewal';
                    $churn = $period === 'year' ? 15 : 8;
                    if ($start <= $now && mt_rand(1, 100) <= $churn) {
                        $leaving = true;
                        $plan = 'free';
                        $periodStart = $periodEnd = null;
                    }
                }
                if (!$leaving) {
                    $status = mt_rand(1, 100) <= 3 ? 'past_due' : 'active';
                    $lastFailure = $status === 'past_due' ? 'Платёж отклонён банком' : null;
                }
            }
        }
        $planId = $plan === 'free' ? $freePlan : $this->plans[$plan]['id'];
        $this->db->execute(
            'INSERT INTO subscriptions (public_id, workspace_id, plan_id, status, period, currency, price_amount, current_period_start, current_period_end, trial_ends_at, cancel_at_period_end, last_failure, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (string) new Ulid(), $workspaceId, $planId, $trialing ? 'trialing' : ($plan === 'free' ? 'active' : $status), $plan === 'free' || $trialing ? null : $period, 'RUB',
                $plan === 'free' || $period === null ? 0 : $this->plans[$plan][$period],
                $periodStart === null ? null : DbTime::format($periodStart), $periodEnd === null ? null : DbTime::format($periodEnd), $trialEnds === null ? null : DbTime::format($trialEnds),
                $plan !== 'free' && mt_rand(1, 100) <= 5 ? 1 : 0, $lastFailure, DbTime::format($registered), DbTime::format($now),
            ],
        );
        $this->db->execute('UPDATE workspaces SET plan_id = ? WHERE id = ?', [$planId, $workspaceId]);

        return $result;
    }

    /**
     * A paid invoice and its successful payment with the money journal entry. Returns the payment's public id.
     */
    private function invoice(Ledger $ledger, int $workspaceId, string $plan, string $period, string $kind, int $amount, int $listPrice, DateTimeImmutable $start, DateTimeImmutable $end, string $email): string
    {
        $paid = DbTime::format($start);
        $this->db->execute(
            'INSERT INTO invoices (public_id, number, workspace_id, plan_id, period, kind, amount, list_price, currency, status, description, customer_email, period_start, period_end, created_at, paid_at, expires_at) '
            . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'RUB', 'paid', ?, ?, ?, ?, ?, ?, ?)",
            [(string) new Ulid(), 'DEMO-' . ++$this->invoiceSeq . '-' . substr((string) new Ulid(), -6), $workspaceId, $this->plans[$plan]['id'], $period, $kind, $amount, $listPrice, 'Демо-счёт', $email, $paid, DbTime::format($end), $paid, $paid, $paid],
        );
        $invoiceId = (int) $this->db->lastInsertId();
        $publicId = (string) new Ulid();
        $provider = mt_rand(1, 100) <= 70 ? 'yookassa' : 'tbank';
        $this->db->execute(
            "INSERT INTO payments (public_id, invoice_id, workspace_id, provider, status, amount, currency, created_at, updated_at) VALUES (?, ?, ?, ?, 'succeeded', ?, 'RUB', ?, ?)",
            [$publicId, $invoiceId, $workspaceId, $provider, $amount, $paid, $paid],
        );
        $row = $this->payments->findByPublicId($publicId) ?? throw new \LogicException('payment');
        $ledger->recordPayment($row);

        return $publicId;
    }
}
