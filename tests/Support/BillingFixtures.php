<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\Billing\BillingPeriod;
use App\Domain\Billing\Invoice;
use App\Domain\Billing\InvoiceKind;
use App\Domain\Billing\InvoiceStatus;
use App\Domain\Billing\Payment;
use App\Domain\Billing\PaymentMethod;
use App\Domain\Billing\PaymentStatus;
use App\Integrations\Payments\TBank\TBankSigner;
use DateTimeImmutable;

/**
 * Recorded (and trimmed) answers of YooKassa and T-Bank from `tests/Fixtures/yookassa/` and `tests/Fixtures/tbank/`, built from the shapes in
 * the providers' documentation (no test reaches a provider). Helpers adapt an answer to the payment a test created.
 */
final class BillingFixtures
{
    public const YOOKASSA_API = 'https://api.yookassa.ru/v3';
    public const YOOKASSA_PAYMENT = '2c3a1f0e-000f-5000-8000-1d1f0a5a7b11';
    public const YOOKASSA_IP = '185.71.76.5';

    public const TBANK_API = 'https://securepay.tinkoff.ru/v2';
    public const TBANK_TERMINAL = 'TestTerminalDEMO';
    public const TBANK_PASSWORD = 'tbank-test-password-not-real';
    public const TBANK_PAYMENT = '2304884';

    /**
     * @param array<string, mixed> $changes top-level keys to replace in the recorded answer
     * @return array<string, mixed>
     */
    public static function yookassa(string $name, array $changes = []): array
    {
        return self::load('yookassa', $name, $changes);
    }

    /**
     * @param array<string, mixed> $changes
     */
    public static function yookassaRaw(string $name, array $changes = []): string
    {
        return json_encode(self::yookassa($name, $changes), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    public static function tbank(string $name, array $changes = []): array
    {
        return self::load('tbank', $name, $changes);
    }

    /**
     * @param array<string, mixed> $changes
     */
    public static function tbankRaw(string $name, array $changes = []): string
    {
        return json_encode(self::tbank($name, $changes), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * A T-Bank notification with a valid `Token`.
     *
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    public static function tbankNotification(array $changes = [], string $password = self::TBANK_PASSWORD): array
    {
        $data = self::tbank('notification_confirmed', $changes);
        $data['Token'] = TBankSigner::token($data, $password);

        return $data;
    }

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    private static function load(string $provider, string $name, array $changes): array
    {
        $body = file_get_contents(dirname(__DIR__) . '/Fixtures/' . $provider . '/' . $name . '.json');
        if ($body === false) {
            throw new \LogicException('Missing fixture ' . $provider . '/' . $name);
        }
        $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);

        return array_replace(is_array($data) ? $data : [], $changes);
    }

    /** An open invoice for a month of a plan, as a gateway sees it. */
    public static function invoice(int $amount = 99000, string $email = 'owner@example.com'): Invoice
    {
        $now = new DateTimeImmutable('2026-10-04 12:00:00');

        return new Invoice(1, '01JTESTINVOICE00000000000X', 'EZ-000001', 7, null, 3, BillingPeriod::Month, InvoiceKind::New, $amount, $amount, 'RUB', InvoiceStatus::Open, 'Тариф «Про» за месяц', $email, $now, $now->modify('+1 month'), $now, null, $now->modify('+1 day'));
    }

    public static function payment(string $provider, ?string $providerId = null, int $amount = 99000): Payment
    {
        return new Payment(1, '01JTESTPAYMENT000000000000', 1, 7, $provider, $providerId, PaymentStatus::Pending, null, $amount, 'RUB', 0, null, true, null, null, new DateTimeImmutable('2026-10-04 12:00:00'));
    }

    public static function method(string $provider, string $providerMethodId): PaymentMethod
    {
        return new PaymentMethod(1, '01JTESTMETHOD000000000000X', 7, $provider, $providerMethodId, 'ws7', 'Карта •• 4477', true, new DateTimeImmutable('2026-10-04 12:00:00'));
    }
}
