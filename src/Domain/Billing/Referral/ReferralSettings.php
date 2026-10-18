<?php

declare(strict_types=1);

namespace App\Domain\Billing\Referral;

use App\Domain\Settings\Settings;

/**
 * The terms of the referral programme, edited by the owner in the admin area: on or off, the share of every payment of an invited person
 * that goes to the inviter, how long the invitation cookie lives and the smallest withdrawal (minor units of rubles).
 */
final class ReferralSettings
{
    public const DEFAULT_PERCENT = 20;
    public const DEFAULT_COOKIE_DAYS = 30;
    public const DEFAULT_MIN_PAYOUT = 50000;

    public function __construct(private readonly Settings $settings)
    {
    }

    public function enabled(): bool
    {
        return $this->settings->get('referral.enabled', true) !== false;
    }

    public function percent(): int
    {
        $value = $this->settings->get('referral.percent', self::DEFAULT_PERCENT);

        return is_int($value) && $value >= 0 && $value <= 90 ? $value : self::DEFAULT_PERCENT;
    }

    public function cookieDays(): int
    {
        $value = $this->settings->get('referral.cookie_days', self::DEFAULT_COOKIE_DAYS);

        return is_int($value) && $value >= 1 && $value <= 365 ? $value : self::DEFAULT_COOKIE_DAYS;
    }

    public function minPayout(): int
    {
        $value = $this->settings->get('referral.min_payout', self::DEFAULT_MIN_PAYOUT);

        return is_int($value) && $value >= 100 ? $value : self::DEFAULT_MIN_PAYOUT;
    }

    /**
     * @return list<string> problems in the owner's words; empty when saved
     */
    public function save(bool $enabled, string $percent, string $cookieDays, string $minPayoutRubles, ?int $actorId): array
    {
        $errors = [];
        if (preg_match('/^\d{1,2}$/', $percent) !== 1 || (int) $percent > 90) {
            $errors[] = 'Процент должен быть целым числом от 0 до 90.';
        }
        if (preg_match('/^\d{1,3}$/', $cookieDays) !== 1 || (int) $cookieDays < 1 || (int) $cookieDays > 365) {
            $errors[] = 'Срок ссылки — от 1 до 365 дней.';
        }
        $rubles = str_replace(',', '.', trim($minPayoutRubles));
        if (preg_match('/^\d{1,7}(\.\d{1,2})?$/', $rubles) !== 1 || (float) $rubles < 1) {
            $errors[] = 'Минимальный вывод — число в рублях, не меньше 1.';
        }
        if ($errors !== []) {
            return $errors;
        }
        $this->settings->set('referral.enabled', $enabled, $actorId);
        $this->settings->set('referral.percent', (int) $percent, $actorId);
        $this->settings->set('referral.cookie_days', (int) $cookieDays, $actorId);
        $this->settings->set('referral.min_payout', (int) round((float) $rubles * 100), $actorId);

        return [];
    }
}
