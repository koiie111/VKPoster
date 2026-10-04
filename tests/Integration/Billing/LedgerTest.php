<?php

declare(strict_types=1);

namespace App\Tests\Integration\Billing;

use App\Domain\Billing\Ledger;
use App\Tests\Support\BillingFixtures;
use App\Tests\Support\BillingTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The money journal: double entry (every transaction sums to zero), balances are sums, recording is idempotent.
 */
#[CoversClass(Ledger::class)]
final class LedgerTest extends BillingTestCase
{
    private function ledger(): Ledger
    {
        return $this->app->container()->get(Ledger::class);
    }

    public function testAPaymentIsOneBalancedTransaction(): void
    {
        $this->ledger()->recordPayment(BillingFixtures::payment('yookassa'));

        $rows = $this->db->select('SELECT txn_id, amount, a.code FROM ledger_entries e JOIN ledger_accounts a ON a.id = e.account_id ORDER BY e.id');
        self::assertCount(2, $rows);
        self::assertSame($rows[0]['txn_id'], $rows[1]['txn_id']);
        self::assertSame(0, (int) $rows[0]['amount'] + (int) $rows[1]['amount'], 'debits and credits cancel out');
        self::assertSame(99000, $this->ledger()->balance('provider:yookassa'));
        self::assertSame(-99000, $this->ledger()->balance(Ledger::REVENUE));
    }

    public function testRecordingTheSamePaymentTwiceWritesOnce(): void
    {
        $payment = BillingFixtures::payment('tbank');

        $this->ledger()->recordPayment($payment);
        $this->ledger()->recordPayment($payment);

        self::assertSame(2, (int) $this->db->select('SELECT COUNT(*) AS c FROM ledger_entries')[0]['c']);
        self::assertSame(99000, $this->ledger()->balance('provider:tbank'));
    }

    public function testARefundReversesTheMoneyAndBalancesStayTheSumOfEntries(): void
    {
        $payment = BillingFixtures::payment('yookassa');
        $this->ledger()->recordPayment($payment);

        $this->ledger()->recordRefund($payment, 30000, 'refund-1');
        $this->ledger()->recordRefund($payment, 30000, 'refund-1');
        $this->ledger()->recordRefund($payment, 9000, 'refund-2');

        self::assertSame(99000 - 30000 - 9000, $this->ledger()->balance('provider:yookassa'));
        self::assertSame(-(99000 - 39000), $this->ledger()->balance(Ledger::REVENUE));
        $sums = $this->db->select('SELECT txn_id, SUM(amount) AS s FROM ledger_entries GROUP BY txn_id');
        self::assertCount(3, $sums);
        foreach ($sums as $row) {
            self::assertSame(0, (int) $row['s'], 'every transaction balances');
        }
    }

    public function testTheBalanceOfAnUnknownAccountIsZero(): void
    {
        self::assertSame(0, $this->ledger()->balance('provider:nobody'));
    }
}
