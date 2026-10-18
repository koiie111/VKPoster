<?php

declare(strict_types=1);

namespace App\Domain\Billing\Referral;

use App\Domain\Audit\AuditLog;
use App\Domain\Billing\BillingException;
use App\Domain\Billing\Ledger;
use App\Domain\Billing\Payment;
use App\Domain\User\User;
use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;
use Symfony\Component\Uid\Ulid;

/**
 * The referral programme. A person has a code and a link; someone who registers within the cookie's lifetime of following it is tied to
 * the inviter for good. Every ruble payment of the invited person's workspaces gives the inviter a share (20 % unless the owner changed it)
 * on a balance kept in the ledger (`referral:user:<id>`). A refund takes the bonus back in proportion. The balance pays for a plan or is
 * withdrawn through a request that staff handle by hand.
 *
 * Self-invitation does not pay: a code of one's own, the same network address as the inviter's devices, or the same saved card as the inviter's
 * puts the link in `flagged` (no bonus until staff decide) or `rejected`.
 */
final class ReferralService
{
    public const COOKIE = 'ez_ref';
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    private const NEW_ACCOUNT_DAYS = 3;

    public function __construct(
        private readonly Connection $db,
        private readonly Ledger $ledger,
        private readonly ReferralSettings $settings,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
    ) {
    }

    // ---- codes and links ------------------------------------------------------------------------------------------

    public function codeFor(int $userId): string
    {
        $row = $this->db->table('referral_codes')->where('user_id', '=', $userId)->first();
        if ($row !== null) {
            return (string) $row['code'];
        }
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $code = '';
            for ($i = 0; $i < 8; ++$i) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            try {
                $this->db->table('referral_codes')->insert(['user_id' => $userId, 'code' => $code, 'created_at' => DbTime::format($this->clock->now())]);

                return $code;
            } catch (\PDOException $e) {
                if (($e->errorInfo[1] ?? 0) !== 1062) {
                    throw $e;
                }
                $again = $this->db->table('referral_codes')->where('user_id', '=', $userId)->first();
                if ($again !== null) {
                    return (string) $again['code'];
                }
            }
        }

        throw new \RuntimeException('Could not make a referral code.');
    }

    public function referrerByCode(string $code): ?int
    {
        $row = preg_match('/^[A-Z0-9]{4,12}$/', strtoupper($code)) === 1 ? $this->db->table('referral_codes')->where('code', '=', strtoupper($code))->first() : null;

        return $row === null ? null : (int) $row['user_id'];
    }

    // ---- attribution ----------------------------------------------------------------------------------------------

    /**
     * Tie a freshly registered person to the one whose link they followed. Quiet when anything is off (an old account, a code that does not
     * exist, a person who already has an inviter).
     */
    public function attribute(User $user, string $code, string $ip): void
    {
        if (!$this->settings->enabled() || $user->createdAt < $this->clock->now()->modify(sprintf('-%d days', self::NEW_ACCOUNT_DAYS))) {
            return;
        }
        $referrerId = $this->referrerByCode($code);
        if ($referrerId === null || $this->db->table('referrals')->where('referred_id', '=', $user->id)->exists()) {
            return;
        }
        $status = 'active';
        $reason = null;
        if ($referrerId === $user->id) {
            $status = 'rejected';
            $reason = 'своя ссылка';
        } elseif ($this->sharesAddress($referrerId, $ip)) {
            $status = 'flagged';
            $reason = 'тот же адрес, что у пригласившего';
        }
        try {
            $this->db->table('referrals')->insert([
                'referrer_id' => $referrerId,
                'referred_id' => $user->id,
                'status' => $status,
                'reason' => $reason,
                'ip_hash' => hash('sha256', $ip),
                'created_at' => DbTime::format($this->clock->now()),
            ]);
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }
        }
    }

    private function sharesAddress(int $referrerId, string $ip): bool
    {
        return $ip !== '' && $this->db->table('user_sessions')->where('user_id', '=', $referrerId)->where('ip', '=', $ip)->exists();
    }

    // ---- money in -------------------------------------------------------------------------------------------------

    /**
     * The inviter's share of a payment. Called when a payment is applied; safe to call twice. Only ruble payments pay a bonus, and not those
     * made with the bonus balance itself.
     */
    public function accrue(Payment $payment): void
    {
        if (!$this->settings->enabled() || $payment->currency !== 'RUB' || $payment->provider === 'balance' || $this->settings->percent() === 0) {
            return;
        }
        $owner = $this->db->table('workspaces')->where('id', '=', $payment->workspaceId)->first();
        $referral = $owner === null ? null : $this->db->table('referrals')->where('referred_id', '=', (int) $owner['owner_id'])->first();
        if ($referral === null || $referral['status'] !== 'active') {
            return;
        }
        if ($this->sharesCard((int) $referral['referrer_id'], (int) $owner['owner_id'])) {
            $this->db->table('referrals')->where('id', '=', (int) $referral['id'])->update(['status' => 'flagged', 'reason' => 'та же карта, что у пригласившего']);
            $this->audit->record('billing.referral_flagged', null, 'referral', (string) $referral['id'], ['reason' => 'same_card'], $payment->workspaceId);

            return;
        }
        $bonus = intdiv($payment->amount * $this->settings->percent(), 100);
        if ($bonus <= 0 || $this->db->table('referral_bonuses')->where('payment_id', '=', $payment->id)->exists()) {
            return;
        }
        $userId = (int) $referral['referrer_id'];
        $this->ledger->post('ref_bonus', $payment->publicId, 'Бонус за оплату приглашённого ' . $payment->publicId, 'RUB', [
            ['code' => 'referral:expense', 'name' => 'Расходы на реферальную программу', 'kind' => 'expense', 'amount' => $bonus],
            ['code' => self::account($userId), 'name' => 'Реферальный баланс', 'kind' => 'liability', 'amount' => -$bonus],
        ]);
        $this->db->table('referral_bonuses')->insert(['referral_id' => (int) $referral['id'], 'payment_id' => $payment->id, 'amount' => $bonus, 'reversed' => 0, 'created_at' => DbTime::format($this->clock->now())]);
    }

    /**
     * Take back the bonus for money that was given back (all of it for a full refund, in proportion for a partial one).
     */
    public function reverse(Payment $payment, int $refunded, string $refundRef): void
    {
        $bonus = $this->db->table('referral_bonuses')->where('payment_id', '=', $payment->id)->first();
        if ($bonus === null || $payment->amount <= 0) {
            return;
        }
        $referral = $this->db->table('referrals')->where('id', '=', (int) $bonus['referral_id'])->first();
        $share = min((int) $bonus['amount'] - (int) $bonus['reversed'], intdiv((int) $bonus['amount'] * $refunded + intdiv($payment->amount, 2), $payment->amount));
        if ($referral === null || $share <= 0) {
            return;
        }
        $posted = $this->ledger->post('ref_reversal', $refundRef, 'Бонус отозван: возврат по платежу ' . $payment->publicId, 'RUB', [
            ['code' => self::account((int) $referral['referrer_id']), 'name' => 'Реферальный баланс', 'kind' => 'liability', 'amount' => $share],
            ['code' => 'referral:expense', 'name' => 'Расходы на реферальную программу', 'kind' => 'expense', 'amount' => -$share],
        ]);
        if ($posted) {
            $this->db->execute('UPDATE referral_bonuses SET reversed = reversed + ? WHERE id = ?', [$share, (int) $bonus['id']]);
        }
    }

    private function sharesCard(int $referrerId, int $referredId): bool
    {
        // The same saved card, as the provider describes it (brand and last digits) with the same provider, on both sides.
        return (int) ($this->db->select(
            'SELECT COUNT(*) AS n FROM payment_methods a JOIN workspaces wa ON wa.id = a.workspace_id JOIN payment_methods b ON b.provider = a.provider AND b.title = a.title JOIN workspaces wb ON wb.id = b.workspace_id WHERE wa.owner_id = ? AND wb.owner_id = ?',
            [$referrerId, $referredId],
        )[0]['n'] ?? 0) > 0;
    }

    // ---- the balance ----------------------------------------------------------------------------------------------

    public static function account(int $userId): string
    {
        return 'referral:user:' . $userId;
    }

    /** Minor units of rubles the person can spend or withdraw (may be below zero after a refund of an already withdrawn bonus). */
    public function balance(int $userId): int
    {
        return -$this->ledger->balance(self::account($userId));
    }

    /**
     * Spend the balance on a payment. False when there is not enough.
     */
    public function spend(int $userId, int $amount, string $paymentPublicId): bool
    {
        if ($amount <= 0 || $this->balance($userId) < $amount) {
            return false;
        }
        $this->ledger->post('ref_spend', $paymentPublicId, 'Оплата тарифа реферальным балансом ' . $paymentPublicId, 'RUB', [
            ['code' => self::account($userId), 'name' => 'Реферальный баланс', 'kind' => 'liability', 'amount' => $amount],
            ['code' => 'referral:spent', 'name' => 'Бонусы, потраченные на оплату', 'kind' => 'income', 'amount' => -$amount],
        ]);

        return true;
    }

    /**
     * @return array{people: int, flagged: int, earned: int, bonuses: list<array{amount: int, created_at: string}>}
     */
    public function summary(int $userId): array
    {
        $earned = (int) ($this->db->select('SELECT COALESCE(SUM(b.amount - b.reversed), 0) AS total FROM referral_bonuses b JOIN referrals r ON r.id = b.referral_id WHERE r.referrer_id = ?', [$userId])[0]['total'] ?? 0);

        return [
            'people' => $this->db->table('referrals')->where('referrer_id', '=', $userId)->where('status', '=', 'active')->count(),
            'flagged' => $this->db->table('referrals')->where('referrer_id', '=', $userId)->where('status', '=', 'flagged')->count(),
            'earned' => $earned,
            'bonuses' => [],
        ];
    }

    // ---- lists for the screens ------------------------------------------------------------------------------------

    /**
     * @return list<array<string, mixed>> withdrawal requests, newest first, with who asked
     */
    public function payouts(?string $status = null, int $limit = 100): array
    {
        $sql = 'SELECT p.*, u.email, u.name FROM referral_payouts p JOIN users u ON u.id = p.user_id' . ($status === null ? '' : ' WHERE p.status = ?') . ' ORDER BY p.id DESC LIMIT ' . max(1, min(500, $limit));

        return $this->db->select($sql, $status === null ? [] : [$status]);
    }

    /**
     * @return list<array<string, mixed>> links held back for a look: who invited whom and why
     */
    public function flagged(): array
    {
        return $this->db->select(
            "SELECT r.id, r.reason, r.created_at, a.email AS referrer_email, b.email AS referred_email FROM referrals r JOIN users a ON a.id = r.referrer_id JOIN users b ON b.id = r.referred_id WHERE r.status = 'flagged' ORDER BY r.id DESC LIMIT 100",
        );
    }

    /**
     * @return list<array<string, mixed>> the people with the most earned, for the owner's overview
     */
    public function top(int $limit = 10): array
    {
        return $this->db->select(
            'SELECT u.id, u.email, u.name, COUNT(DISTINCT r.id) AS invited, COALESCE(SUM(b.amount - b.reversed), 0) AS earned FROM referrals r JOIN users u ON u.id = r.referrer_id LEFT JOIN referral_bonuses b ON b.referral_id = r.id WHERE r.status = \'active\' GROUP BY u.id, u.email, u.name ORDER BY earned DESC LIMIT ' . max(1, min(50, $limit)),
        );
    }

    /**
     * @return list<array<string, mixed>> the person's own withdrawal requests
     */
    public function payoutsOf(int $userId): array
    {
        return $this->db->select('SELECT public_id, amount, status, staff_note, created_at FROM referral_payouts WHERE user_id = ? ORDER BY id DESC LIMIT 20', [$userId]);
    }

    // ---- withdrawals ----------------------------------------------------------------------------------------------

    /**
     * Ask to withdraw money. The amount leaves the balance at once; staff pay it by hand and mark the request.
     *
     * @throws BillingException
     */
    public function requestPayout(User $user, int $amount, string $details): string
    {
        $details = trim($details);
        if (mb_strlen($details) < 5 || mb_strlen($details) > 500) {
            throw new BillingException('Напишите, куда перевести деньги: номер карты или телефон СБП и имя получателя.');
        }
        if ($amount < $this->settings->minPayout()) {
            throw new BillingException('Минимальная сумма вывода — ' . \App\Support\Money::format($this->settings->minPayout()) . '.');
        }
        $publicId = (string) new Ulid();
        $this->db->transaction(function () use ($user, $amount, $details, $publicId): void {
            if ($this->balance($user->id) < $amount) {
                throw new BillingException('На балансе меньше этой суммы.');
            }
            $this->ledger->post('ref_payout', $publicId, 'Заявка на вывод ' . $publicId, 'RUB', [
                ['code' => self::account($user->id), 'name' => 'Реферальный баланс', 'kind' => 'liability', 'amount' => $amount],
                ['code' => 'referral:payouts', 'name' => 'Выводы реферального баланса', 'kind' => 'liability', 'amount' => -$amount],
            ]);
            $this->db->table('referral_payouts')->insert(['public_id' => $publicId, 'user_id' => $user->id, 'amount' => $amount, 'details' => $details, 'status' => 'requested', 'created_at' => DbTime::format($this->clock->now())]);
        });
        $this->audit->record('billing.payout_requested', $user->id, 'payout', $publicId, ['amount' => $amount]);

        return $publicId;
    }

    /**
     * Staff mark a request as paid, or reject it (the money returns to the balance).
     *
     * @throws BillingException
     */
    public function handlePayout(string $publicId, bool $paid, string $note, int $staffId): void
    {
        $row = $this->db->table('referral_payouts')->where('public_id', '=', $publicId)->first();
        if ($row === null || $row['status'] !== 'requested') {
            throw new BillingException('Эта заявка уже обработана.');
        }
        $this->db->transaction(function () use ($row, $paid, $note, $staffId, $publicId): void {
            $amount = (int) $row['amount'];
            if (!$paid) {
                $this->ledger->post('ref_payout_reject', $publicId, 'Заявка на вывод отклонена ' . $publicId, 'RUB', [
                    ['code' => 'referral:payouts', 'name' => 'Выводы реферального баланса', 'kind' => 'liability', 'amount' => $amount],
                    ['code' => self::account((int) $row['user_id']), 'name' => 'Реферальный баланс', 'kind' => 'liability', 'amount' => -$amount],
                ]);
            }
            $this->db->table('referral_payouts')->where('id', '=', (int) $row['id'])->update([
                'status' => $paid ? 'paid' : 'rejected',
                'staff_note' => mb_substr(trim($note), 0, 300),
                'handled_by' => $staffId,
                'handled_at' => DbTime::format($this->clock->now()),
            ]);
        });
        $this->audit->record($paid ? 'billing.payout_paid' : 'billing.payout_rejected', $staffId, 'payout', $publicId, ['amount' => (int) $row['amount']]);
    }

    /**
     * Staff decide about a flagged link: let it pay bonuses or reject it.
     */
    public function decide(int $referralId, bool $approve, int $staffId): void
    {
        $this->db->table('referrals')->where('id', '=', $referralId)->where('status', '=', 'flagged')->update(['status' => $approve ? 'active' : 'rejected']);
        $this->audit->record('billing.referral_decided', $staffId, 'referral', (string) $referralId, ['approved' => $approve ? '1' : '0']);
    }
}
