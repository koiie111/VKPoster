<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\Billing\Ledger;
use App\Domain\Billing\PaymentRepository;
use App\Kernel\Database\Connection;
use App\Support\DbTime;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\Uid\Ulid;

/**
 * The "reference month" for the business metrics: September 2026 with people, invoices, payments, refunds, trials and activity whose
 * every figure is known in advance (see `MetricsTest`). Rows are written directly so that the answer does not depend on the code under test.
 *
 * Workspaces (price per month in kopecks): A pro 100000 paid 09-01 (trial 08-30); B start 30000 paid 09-05 (trial 09-01); C pro yearly
 * 1 200 000 paid 09-10 (trial 09-02); D start 30000 paid 08-20 and renewed 09-20; E start 30000 paid 08-25, not renewed (lost 09-25);
 * F start 30000 paid 09-01, upgraded to pro 100000 on 09-15 (prorated payment 35000); G pro 100000 paid 09-12 and refunded in full 09-13;
 * H trial from 09-20 that never paid.
 */
final class MetricsFixture
{
    /** @var array<string, int> workspace letter => id */
    public array $workspaces = [];

    /** @var array<string, int> workspace letter => owner id */
    public array $owners = [];

    public function __construct(private readonly Connection $db, private readonly FakeClock $clock, private readonly Ledger $ledger, private readonly PaymentRepository $payments)
    {
    }

    public function build(): void
    {
        $this->person('A', '2026-08-30 09:00', 'vk-ads');
        $this->person('B', '2026-08-31 10:00', 'vk-ads');
        $this->person('C', '2026-09-02 10:00', null, 'news.example.com');
        $this->person('D', '2026-08-18 10:00', null);
        $this->person('E', '2026-08-24 10:00', null);
        $this->person('F', '2026-09-01 08:00', 'vk-ads');
        $this->person('G', '2026-09-11 08:00', null);
        $this->person('H', '2026-09-19 08:00', 'newsletter');

        foreach (['A' => '2026-08-30 10:00', 'B' => '2026-09-01 10:00', 'C' => '2026-09-02 12:00', 'H' => '2026-09-20 10:00'] as $letter => $at) {
            $this->audit('billing.trial_started', $letter, $at);
        }

        $this->pay('A', 'pro', 'month', 100000, 100000, '2026-09-01 12:00', '2026-09-01 12:00', '2026-10-01 12:00', 'new');
        $this->pay('B', 'start', 'month', 30000, 30000, '2026-09-05 12:00', '2026-09-05 12:00', '2026-10-05 12:00', 'new');
        $this->pay('C', 'pro', 'year', 1200000, 1200000, '2026-09-10 12:00', '2026-09-10 12:00', '2027-09-10 12:00', 'new');
        $this->pay('D', 'start', 'month', 30000, 30000, '2026-08-20 12:00', '2026-08-20 12:00', '2026-09-20 12:00', 'new');
        $this->pay('D', 'start', 'month', 30000, 30000, '2026-09-20 12:00', '2026-09-20 12:00', '2026-10-20 12:00', 'renewal');
        $this->pay('E', 'start', 'month', 30000, 30000, '2026-08-25 12:00', '2026-08-25 12:00', '2026-09-25 12:00', 'new');
        $this->pay('F', 'start', 'month', 30000, 30000, '2026-09-01 14:00', '2026-09-01 14:00', '2026-10-01 14:00', 'new');
        $this->pay('F', 'pro', 'month', 35000, 100000, '2026-09-15 12:00', '2026-09-15 12:00', '2026-10-15 12:00', 'upgrade');
        $g = $this->pay('G', 'pro', 'month', 100000, 100000, '2026-09-12 12:00', '2026-09-12 12:00', '2026-10-12 12:00', 'new');
        $this->refund($g, '2026-09-13 12:00');

        foreach ([['A', '2026-09-04'], ['A', '2026-09-10'], ['A', '2026-09-11'], ['B', '2026-09-10'], ['C', '2026-09-10'], ['C', '2026-09-20']] as [$letter, $day]) {
            $this->db->execute('INSERT IGNORE INTO user_activity_days (user_id, day) VALUES (?, ?)', [$this->owners[$letter], $day]);
        }
    }

    private function person(string $letter, string $registeredAt, ?string $utmSource, ?string $referrer = null): void
    {
        $now = DbTime::format(new DateTimeImmutable($registeredAt, new DateTimeZone('UTC')));
        $this->db->execute(
            'INSERT INTO users (email, name, password_hash, created_at, updated_at, email_verified_at) VALUES (?, ?, NULL, ?, ?, ?)',
            ['metrics-' . strtolower($letter) . '@example.com', 'Клиент ' . $letter, $now, $now, $letter === 'H' ? null : $now],
        );
        $userId = (int) $this->db->lastInsertId();
        $this->db->execute(
            'INSERT INTO workspaces (public_id, owner_id, name, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
            [(string) new Ulid(), $userId, 'Пространство ' . $letter, $now, $now],
        );
        $this->owners[$letter] = $userId;
        $this->workspaces[$letter] = (int) $this->db->lastInsertId();
        if ($utmSource !== null || $referrer !== null) {
            $this->db->execute('INSERT INTO user_attribution (user_id, utm_source, referrer, first_seen_at) VALUES (?, ?, ?, ?)', [$userId, $utmSource, $referrer, $now]);
        }
    }

    private function audit(string $action, string $letter, string $at): void
    {
        $this->db->execute(
            'INSERT INTO audit_log (workspace_id, action, created_at) VALUES (?, ?, ?)',
            [$this->workspaces[$letter], $action, DbTime::format(new DateTimeImmutable($at, new DateTimeZone('UTC')))],
        );
    }

    /**
     * A paid invoice with its successful payment and the ledger entry, all at `$paidAt`.
     */
    private function pay(string $letter, string $plan, string $period, int $amount, int $listPrice, string $paidAt, string $start, string $end, string $kind): string
    {
        $this->clock->set($paidAt);
        $planId = (int) $this->db->select('SELECT id FROM plans WHERE code = ?', [$plan])[0]['id'];
        $paid = DbTime::format(new DateTimeImmutable($paidAt, new DateTimeZone('UTC')));
        $number = 'M-' . substr((string) new Ulid(), -10);
        $this->db->execute(
            'INSERT INTO invoices (public_id, number, workspace_id, plan_id, period, kind, amount, list_price, currency, status, description, customer_email, period_start, period_end, created_at, paid_at, expires_at) '
            . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'RUB', 'paid', 'тест', 'x@example.com', ?, ?, ?, ?, ?)",
            [(string) new Ulid(), $number, $this->workspaces[$letter], $planId, $period, $kind, $amount, $listPrice, DbTime::format(new DateTimeImmutable($start, new DateTimeZone('UTC'))), DbTime::format(new DateTimeImmutable($end, new DateTimeZone('UTC'))), $paid, $paid, $paid],
        );
        $invoiceId = (int) $this->db->lastInsertId();
        $publicId = (string) new Ulid();
        $this->db->execute(
            "INSERT INTO payments (public_id, invoice_id, workspace_id, provider, status, amount, currency, created_at, updated_at) VALUES (?, ?, ?, 'yookassa', 'succeeded', ?, 'RUB', ?, ?)",
            [$publicId, $invoiceId, $this->workspaces[$letter], $amount, $paid, $paid],
        );
        $payment = $this->payments->findByPublicId($publicId) ?? throw new \LogicException('payment');
        $this->ledger->recordPayment($payment);

        return $publicId;
    }

    private function refund(string $paymentPublicId, string $at): void
    {
        $this->clock->set($at);
        $payment = $this->payments->findByPublicId($paymentPublicId) ?? throw new \LogicException('payment');
        $this->ledger->recordRefund($payment, $payment->amount, 'refund-' . $paymentPublicId);
        $this->db->execute("UPDATE payments SET status = 'refunded', refunded_amount = amount, updated_at = ? WHERE public_id = ?", [DbTime::format(new DateTimeImmutable($at, new DateTimeZone('UTC'))), $paymentPublicId]);
    }
}
