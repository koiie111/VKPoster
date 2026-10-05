<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;
use Symfony\Component\Uid\Ulid;

/**
 * The double-entry journal of money. Every movement is one transaction of at least two entries that sum to zero (debits positive,
 * credits negative); an account's balance is the sum of its entries and is never stored as a number that can be edited.
 *
 * For now it records payments: money arriving from a provider (debit `provider:<name>`, the provider owes it to us) against revenue
 * (credit `revenue:subscriptions`). A refund is the same movement reversed. Internal balances (referral bonuses, AI credits) get their
 * own accounts later without any change to this class.
 */
final class Ledger
{
    public const REVENUE = 'revenue:subscriptions';

    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    /**
     * Record that a payment was received. Safe to call twice for one payment: the second call writes nothing.
     */
    public function recordPayment(Payment $payment): void
    {
        $this->move('payment', $payment, $payment->amount, 'Оплата ' . $payment->publicId);
    }

    /**
     * Record that money was given back for a payment.
     */
    public function recordRefund(Payment $payment, int $amount, string $refundRef): void
    {
        $this->move('refund', $payment, -$amount, 'Возврат по платежу ' . $payment->publicId, $refundRef);
    }

    /**
     * Sum of the entries of an account (0 for an account that does not exist yet).
     */
    public function balance(string $code): int
    {
        $rows = $this->db->select(
            'SELECT COALESCE(SUM(e.amount), 0) AS total FROM ledger_entries e JOIN ledger_accounts a ON a.id = e.account_id WHERE a.code = ?',
            [$code],
        );

        return (int) ($rows[0]['total'] ?? 0);
    }

    /** Units of what staff can give: days of the plan, AI credits (stage 17), and money on the balance (kopecks). The unit is the entry's currency code. */
    public const GRANT_UNITS = ['days' => 'DAY', 'credits' => 'CRD', 'balance' => 'RUB'];

    /**
     * Give a workspace days, credits or money by hand. One transaction of two entries: the expense account `grants:<unit>` is debited and the
     * workspace's wallet `wallet:<unit>:<workspace>` credited, so the balance of the wallet is always the sum of what was granted (and
     * later spent). The reason is part of the entry; who did it is in the audit log.
     *
     * @param string $unit key of `GRANT_UNITS`
     * @return string id of the transaction
     */
    public function grant(string $workspacePublicId, string $unit, int $amount, string $reason): string
    {
        $code = self::GRANT_UNITS[$unit] ?? throw new \InvalidArgumentException('Unknown grant unit.');
        if ($amount <= 0) {
            throw new \InvalidArgumentException('A grant must be positive.');
        }
        $txn = (string) new Ulid();
        $memo = mb_substr('Начисление вручную: ' . $reason, 0, 255);
        $this->db->transaction(function (Connection $db) use ($workspacePublicId, $code, $amount, $memo, $txn): void {
            $expense = $this->account($db, 'grants:' . $code, 'Начисления вручную (' . $code . ')', 'expense', $code === 'RUB' ? 'RUB' : $code);
            $wallet = $this->account($db, 'wallet:' . $code . ':' . $workspacePublicId, 'Кошелёк пространства (' . $code . ')', 'liability', $code === 'RUB' ? 'RUB' : $code);
            $now = DbTime::format($this->clock->now());
            foreach ([[$expense, $amount], [$wallet, -$amount]] as [$account, $value]) {
                $db->table('ledger_entries')->insert([
                    'txn_id' => $txn,
                    'account_id' => $account,
                    'amount' => $value,
                    'currency' => $code,
                    'ref_type' => 'grant',
                    'ref_id' => $txn,
                    'memo' => $memo,
                    'created_at' => $now,
                ]);
            }
        });

        return $txn;
    }

    /**
     * What a workspace holds in its wallet, by unit key (`days`, `credits`, `balance`).
     *
     * @return array<string, int>
     */
    public function wallet(string $workspacePublicId): array
    {
        $result = array_fill_keys(array_keys(self::GRANT_UNITS), 0);
        foreach (self::GRANT_UNITS as $key => $code) {
            $result[$key] = -$this->balance('wallet:' . $code . ':' . $workspacePublicId);
        }

        return $result;
    }

    /**
     * @param int $amount positive: money in; negative: money out
     */
    private function move(string $refType, Payment $payment, int $amount, string $memo, ?string $refId = null): void
    {
        $refId ??= $payment->publicId;
        $this->db->transaction(function (Connection $db) use ($refType, $payment, $amount, $memo, $refId): void {
            // The reference is unique per kind of movement, which makes recording idempotent.
            if ($db->table('ledger_entries')->where('ref_type', '=', $refType)->where('ref_id', '=', $refId)->exists()) {
                return;
            }
            $cash = $this->account($db, 'provider:' . $payment->provider, 'Расчёты с провайдером ' . $payment->provider, 'asset', $payment->currency);
            $revenue = $this->account($db, self::REVENUE, 'Выручка от подписок', 'income', $payment->currency);
            $txn = (string) new Ulid();
            $now = DbTime::format($this->clock->now());
            foreach ([[$cash, $amount], [$revenue, -$amount]] as [$account, $value]) {
                $db->table('ledger_entries')->insert([
                    'txn_id' => $txn,
                    'account_id' => $account,
                    'amount' => $value,
                    'currency' => $payment->currency,
                    'ref_type' => $refType,
                    'ref_id' => $refId,
                    'memo' => $memo,
                    'created_at' => $now,
                ]);
            }
        });
    }

    private function account(Connection $db, string $code, string $name, string $kind, string $currency): int
    {
        $row = $db->table('ledger_accounts')->where('code', '=', $code)->first();
        if ($row !== null) {
            return (int) $row['id'];
        }
        try {
            return (int) $db->table('ledger_accounts')->insert(['code' => $code, 'name' => $name, 'kind' => $kind, 'currency' => $currency, 'created_at' => DbTime::format($this->clock->now())]);
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }
            $row = $db->table('ledger_accounts')->where('code', '=', $code)->first();

            return (int) ($row['id'] ?? throw $e);
        }
    }
}
