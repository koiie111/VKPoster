<?php

declare(strict_types=1);

namespace App\Domain\Admin;

use App\Domain\Audit\AuditLog;
use App\Domain\Auth\SessionRegistry;
use App\Domain\Billing\Ledger;
use App\Domain\Billing\SubscriptionRepository;
use App\Domain\User\User;
use App\Domain\User\UserRepository;
use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;

/**
 * What staff do to a person's account besides blocking: sign them out everywhere, reset their second factor, confirm their email by hand,
 * leave a private note, and give them days, credits or money. Every action is audited with before/after values; money-like gifts go
 * through the ledger with a mandatory reason (never by editing a number). Rules about who may do what are the routes' job (permissions and
 * re-confirmation); the rules about whom they may be done to (not another member of staff) are here.
 */
final class UserActions
{
    /** The most staff can give at once, per unit: a typo cannot hand out a fortune. */
    private const LIMITS = ['days' => 3650, 'credits' => 1000000, 'balance' => 100000000];

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly UserRepository $users,
        private readonly SessionRegistry $sessions,
        private readonly Ledger $ledger,
        private readonly SubscriptionRepository $subscriptions,
        private readonly AuditLog $audit,
        private readonly StaffAccess $staff,
    ) {
    }

    /**
     * @return int number of devices that were signed out
     */
    public function signOutEverywhere(User $target, User $by): int
    {
        $count = $this->sessions->revokeAll($target->id);
        $this->audit->record('admin.user_signed_out', $by->id, 'user', (string) $target->id, ['sessions' => $count]);

        return $count;
    }

    /**
     * Take the second factor away (the person lost the device and the recovery codes). They are signed out everywhere and set it up again.
     * Not for staff: their protection is not something support can switch off.
     *
     * @return string|null why it was refused
     */
    public function resetTwoFactor(User $target, User $by): ?string
    {
        if ($this->staff->isStaff($target)) {
            return 'У сотрудника двухфакторную защиту сбросить нельзя.';
        }
        if (!$target->hasTwoFactor()) {
            return 'У этого человека двухфакторная защита не включена.';
        }
        $this->users->clearTotp($target->id);
        $this->db->execute('DELETE FROM recovery_codes WHERE user_id = ?', [$target->id]);
        $this->sessions->revokeAll($target->id);
        $this->audit->record('admin.user_2fa_reset', $by->id, 'user', (string) $target->id, ['before' => 'on', 'after' => 'off']);

        return null;
    }

    public function verifyEmail(User $target, User $by): bool
    {
        if ($target->email === null || $target->isVerified()) {
            return false;
        }
        $this->users->markVerified($target->id);
        $this->audit->record('admin.user_email_verified', $by->id, 'user', (string) $target->id, ['before' => 'unverified', 'after' => 'verified']);

        return true;
    }

    public function addNote(User $target, User $by, string $body): bool
    {
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > 2000) {
            return false;
        }
        $this->db->table('admin_notes')->insert(['user_id' => $target->id, 'author_id' => $by->id, 'body' => $body, 'created_at' => DbTime::format($this->clock->now())]);
        $this->audit->record('admin.user_note_added', $by->id, 'user', (string) $target->id);

        return true;
    }

    /**
     * Give a workspace of the person days of the plan, AI credits or money on the balance. Days move the end of the paid (or trial) period;
     * credits and money are recorded in the workspace's wallet for the features that spend them.
     *
     * @param string $unit `days`, `credits` or `balance` (money: the amount is in kopecks)
     * @return string|null why it was refused (a sentence for the staff member), null when done
     */
    public function grant(User $target, string $workspacePublicId, string $unit, int $amount, string $reason, User $by): ?string
    {
        $reason = trim($reason);
        if (!isset(Ledger::GRANT_UNITS[$unit])) {
            return 'Выберите, что начислить: дни, кредиты или деньги.';
        }
        if ($amount <= 0 || $amount > self::LIMITS[$unit]) {
            return 'Количество должно быть больше нуля и не больше ' . self::LIMITS[$unit] . '.';
        }
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 200) {
            return 'Напишите причину начисления (от 3 до 200 символов): она останется в журнале.';
        }
        $rows = $this->db->select('SELECT id, public_id FROM workspaces WHERE public_id = ? AND owner_id = ?', [$workspacePublicId, $target->id]);
        if ($rows === []) {
            return 'Начислять можно только в пространство, которым человек владеет.';
        }
        $workspaceId = (int) $rows[0]['id'];
        $before = ['wallet' => $this->ledger->wallet($workspacePublicId)[$unit]];
        if ($unit === 'days') {
            $extended = $this->extend($workspaceId, $amount);
            if ($extended === null) {
                return 'У пространства нет оплаченного или пробного периода, который можно продлить. Сначала выдайте тариф.';
            }
            $before['period_end'] = $extended[0];
        }
        $txn = $this->ledger->grant($workspacePublicId, $unit, $amount, $reason);
        $this->audit->record('admin.grant', $by->id, 'workspace', $workspacePublicId, ['kind' => $unit, 'amount' => $amount, 'reason' => $reason, 'before' => json_encode($before, JSON_THROW_ON_ERROR), 'after' => json_encode(['wallet' => $before['wallet'] + $amount], JSON_THROW_ON_ERROR), 'txn' => $txn], $workspaceId);

        return null;
    }

    /**
     * Move the end of the current paid or trial period `$days` days later.
     *
     * @return array{0: string, 1: string}|null the end before and after, or null when there is no period to extend
     */
    private function extend(int $workspaceId, int $days): ?array
    {
        $subscription = $this->subscriptions->findByWorkspace($workspaceId);
        if ($subscription === null || $subscription->currentPeriodEnd === null) {
            return null;
        }
        $end = $subscription->currentPeriodEnd;
        $values = ['current_period_end' => DbTime::format($end->modify('+' . $days . ' days'))];
        if ($subscription->trialEndsAt !== null) {
            $values['trial_ends_at'] = DbTime::format($subscription->trialEndsAt->modify('+' . $days . ' days'));
        }
        if ($subscription->nextRenewalAttemptAt !== null) {
            $values['next_renewal_attempt_at'] = DbTime::format($subscription->nextRenewalAttemptAt->modify('+' . $days . ' days'));
        }
        $this->subscriptions->update($subscription->id, $values);

        return [$end->format('Y-m-d H:i'), $end->modify('+' . $days . ' days')->format('Y-m-d H:i')];
    }
}
