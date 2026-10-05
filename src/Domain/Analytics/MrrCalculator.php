<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Kernel\Database\Connection;
use App\Support\DbTime;
use DateTimeImmutable;

/**
 * Monthly recurring revenue of every paying workspace at an instant, from the invoices that were paid and cover that instant: a workspace
 * is "paying" while a paid invoice's period contains the moment, and its MRR is the plan's list price for that period spread over its
 * months (a yearly 12 000 is 1 000 a month). Where two invoices overlap (an upgrade is paid inside a running period) the one whose period
 * started later wins. An invoice whose payment was refunded in full does not count, and a plan given free of charge never does.
 *
 * Because it reads invoices and not a stored counter, the answer for any past day can be recomputed at any time and is always the same.
 */
final class MrrCalculator
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return array<int, array{plan: string, currency: string, mrr: int}> workspace id => what it pays per month
     */
    public function at(DateTimeImmutable $moment): array
    {
        $t = DbTime::format($moment);
        $rows = $this->db->select(
            'SELECT x.workspace_id, x.plan, x.currency, x.list_price, x.period FROM ('
            . 'SELECT i.workspace_id, p.code AS plan, i.currency, i.list_price, i.period, '
            . 'ROW_NUMBER() OVER (PARTITION BY i.workspace_id ORDER BY i.period_start DESC, i.id DESC) AS rn '
            . 'FROM invoices i JOIN plans p ON p.id = i.plan_id '
            . "WHERE i.status = 'paid' AND i.paid_at <= ? AND i.period_start <= ? AND i.period_end > ? "
            . "AND EXISTS (SELECT 1 FROM payments pay WHERE pay.invoice_id = i.id AND pay.status = 'succeeded')"
            . ') x WHERE x.rn = 1',
            [$t, $t, $t],
        );
        $result = [];
        foreach ($rows as $row) {
            $months = (string) $row['period'] === 'year' ? 12 : 1;
            $result[(int) $row['workspace_id']] = [
                'plan' => (string) $row['plan'],
                'currency' => (string) $row['currency'],
                'mrr' => intdiv((int) $row['list_price'], $months),
            ];
        }

        return $result;
    }
}
