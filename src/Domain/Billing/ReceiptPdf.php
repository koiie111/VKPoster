<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Kernel\Config;
use App\Support\Money;
use App\Support\Pdf\TextPdf;
use App\Support\Pdf\TrueTypeFont;
use App\Support\RuDates;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The receipt of a paid invoice as a one-page PDF. It is a payment receipt of the service, not the fiscal receipt: the 54-FZ check
 * comes from the payment provider's online cash register by email, and the page says so.
 */
final class ReceiptPdf
{
    private const PROVIDERS = ['yookassa' => 'ЮKassa', 'tbank' => 'Т-Банк', 'fake' => 'Тестовая оплата'];

    public function __construct(private readonly Config $config)
    {
    }

    public function render(Invoice $invoice, ?Payment $payment, string $planName, string $workspaceName, string $timezone): string
    {
        $font = TrueTypeFont::fromFile($this->config->string('billing.receipt_font'));
        $pdf = new TextPdf($font, 'Квитанция ' . $invoice->number);
        $zone = new DateTimeZone($timezone);
        $left = 56.0;
        $right = TextPdf::WIDTH - 56.0;
        $y = 770.0;

        $pdf->text($left, $y, 22, 'Квитанция об оплате');
        $y -= 22;
        $pdf->text($left, $y, 11, $this->config->string('app.name', 'ezposter') . ' · счёт ' . $invoice->number);
        $y -= 14;
        $pdf->rule($left, $y, $right);
        $y -= 28;

        $paidAt = $invoice->paidAt ?? $invoice->createdAt;
        $rows = [
            ['Дата оплаты', self::moment($paidAt, $zone)],
            ['Пространство', $workspaceName],
            ['Плательщик', $invoice->customerEmail],
            ['Тариф', $planName !== '' ? $planName : '—'],
            ['Назначение', $invoice->description],
            ['Оплаченный период', self::day($invoice->periodStart, $zone) . ' — ' . self::day($invoice->periodEnd, $zone)],
            ['Способ оплаты', $payment === null ? '—' : (self::PROVIDERS[$payment->provider] ?? $payment->provider)],
        ];
        $seller = trim($this->config->string('billing.seller.name'));
        if ($seller !== '') {
            $inn = trim($this->config->string('billing.seller.inn'));
            $rows[] = ['Продавец', $seller . ($inn !== '' ? ', ИНН ' . $inn : '')];
        }
        foreach ($rows as [$label, $value]) {
            $pdf->text($left, $y, 10, $label);
            $lines = $pdf->wrap($value, 11, $right - $left - 150);
            foreach ($lines === [] ? [''] : $lines as $line) {
                $pdf->text($left + 150, $y, 11, $line);
                $y -= 16;
            }
            $y -= 6;
        }

        $y -= 4;
        $pdf->rule($left, $y + 12, $right);
        $amount = Money::format($invoice->amount, $invoice->currency);
        $pdf->text($left, $y - 10, 12, 'Сумма');
        $pdf->text($right - $pdf->measure($amount, 18), $y - 12, 18, $amount);
        $y -= 36;
        if ($this->config->string('billing.tax.vat') === 'none' || $this->config->string('billing.tax.tax_system') === 'npd') {
            $pdf->text($left, $y, 10, 'НДС не облагается');
            $y -= 22;
        }

        foreach ($pdf->wrap($this->config->string('billing.tax.tax_system') === 'npd' ? 'Это квитанция об оплате сервиса. Чек как самозанятый формируется в приложении «Мой налог».' : 'Это квитанция об оплате сервиса. Кассовый чек по 54-ФЗ присылает платёжная система на почту плательщика.', 9, $right - $left) as $line) {
            $pdf->text($left, $y, 9, $line);
            $y -= 13;
        }

        return $pdf->render();
    }

    private static function moment(DateTimeImmutable $at, DateTimeZone $zone): string
    {
        $local = $at->setTimezone($zone);

        return RuDates::dayMonth($local) . ' ' . $local->format('Y, H:i') . ' (' . $zone->getName() . ')';
    }

    private static function day(DateTimeImmutable $at, DateTimeZone $zone): string
    {
        $local = $at->setTimezone($zone);

        return RuDates::dayMonth($local) . ' ' . $local->format('Y');
    }
}
