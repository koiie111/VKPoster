<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Notification\MailComposer;
use App\Domain\User\UserRepository;
use App\Domain\Workspace\Workspace;
use App\Domain\Workspace\WorkspaceRepository;
use App\Kernel\Config;
use App\Support\Money;
use App\Support\RuDates;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Emails about money and plans, always to the workspace owner (the person who pays). Queued, so a slow mail server never holds up a
 * payment webhook or the renewal job. Texts live in `templates/emails/billing_*`.
 */
final class BillingMailer
{
    public function __construct(
        private readonly MailComposer $mail,
        private readonly Config $config,
        private readonly WorkspaceRepository $workspaces,
        private readonly UserRepository $users,
    ) {
    }

    public function paymentReceived(Invoice $invoice, Plan $plan, DateTimeImmutable $paidUntil): void
    {
        $this->send($invoice->workspaceId, 'Оплата получена: тариф «' . $plan->name . '»', 'billing_paid', static fn (Workspace $w): array => [
            'plan' => $plan->name,
            'amount' => Money::format($invoice->amount, $invoice->currency),
            'number' => $invoice->number,
            'until' => self::date($paidUntil, $w),
            'description' => $invoice->description,
        ]);
    }

    /**
     * @param DateTimeImmutable|null $nextAttempt when we try again (null: no more attempts)
     * @param DateTimeImmutable $periodEnd when the paid period ends (the plan works until then and a bit longer)
     * @param bool $noMethod there is no saved card to charge, so the customer has to pay by hand
     */
    public function renewalFailed(int $workspaceId, Plan $plan, ?DateTimeImmutable $nextAttempt, DateTimeImmutable $periodEnd, int $graceDays, bool $noMethod): void
    {
        $this->send($workspaceId, $noMethod ? 'Пора продлить тариф «' . $plan->name . '»' : 'Не удалось продлить тариф «' . $plan->name . '»', 'billing_failed', fn (Workspace $w): array => [
            'plan' => $plan->name,
            'no_method' => $noMethod ? '1' : '',
            'next' => $nextAttempt === null ? '' : self::date($nextAttempt, $w),
            'until' => self::date($periodEnd, $w),
            'grace_until' => self::date($periodEnd->modify(sprintf('+%d days', $graceDays)), $w),
            'link' => $this->link($w, '/billing/plans'),
        ]);
    }

    /**
     * @param string $reason trial | canceled | unpaid
     */
    public function downgraded(int $workspaceId, string $reason, int $pausedChannels): void
    {
        $subject = match ($reason) {
            'trial' => 'Пробный период закончился',
            'canceled' => 'Подписка закончилась',
            default => 'Тариф отключён: не удалось получить оплату',
        };
        $this->send($workspaceId, $subject, 'billing_downgraded', fn (Workspace $w): array => [
            'reason' => $reason,
            'paused' => $pausedChannels,
            'link' => $this->link($w, '/billing/plans'),
        ]);
    }

    public function trialEnding(int $workspaceId, DateTimeImmutable $endsAt): void
    {
        $this->send($workspaceId, 'Пробный период заканчивается', 'billing_trial_ending', fn (Workspace $w): array => [
            'until' => self::date($endsAt, $w),
            'link' => $this->link($w, '/billing/plans'),
        ]);
    }

    /**
     * @param callable(Workspace): array<string, scalar|null> $data
     */
    private function send(int $workspaceId, string $subject, string $template, callable $data): void
    {
        $workspace = $this->workspaces->findById($workspaceId);
        $owner = $workspace === null ? null : $this->users->find($workspace->ownerId);
        if ($workspace === null || $owner === null || $owner->email === null || $owner->email === '') {
            return;
        }
        $payload = $data($workspace);
        $payload['name'] = $owner->name;
        $payload['workspace'] = $workspace->name;
        $payload += ['link' => $this->link($workspace, '/billing')];
        $this->mail->send($owner->email, $subject, $template, $payload);
    }

    private function link(Workspace $workspace, string $path): string
    {
        return rtrim($this->config->string('app.url'), '/') . '/w/' . $workspace->publicId . $path;
    }

    private static function date(DateTimeImmutable $at, Workspace $workspace): string
    {
        try {
            $local = $at->setTimezone(new DateTimeZone($workspace->timezone));
        } catch (\Exception) {
            $local = $at;
        }

        return RuDates::dayMonth($local) . ' ' . $local->format('Y');
    }
}
