<?php

declare(strict_types=1);

namespace App\Domain\Admin;

use App\Domain\Settings\Settings;
use App\Kernel\Database\Connection;
use App\Support\DbTime;
use DateTimeImmutable;

/**
 * Paid invoices of a period for the accountant: date, number, who paid for what, the amount, the provider, the provider's fee and
 * what was refunded. The fee is not reported by every provider, so it is an estimate from the percentage the owner enters per provider
 * (`finance.fee_percent`); an empty percentage leaves the column empty rather than inventing a number.
 */
final class FinanceExport
{
    public const SETTING = 'finance.fee_percent';
    public const LIMIT = 100000;

    public function __construct(private readonly Connection $db, private readonly Settings $settings)
    {
    }

    /**
     * Provider fee percentages by provider code (only those that are set).
     *
     * @return array<string, float>
     */
    public function fees(): array
    {
        $stored = $this->settings->get(self::SETTING, []);
        $result = [];
        foreach (is_array($stored) ? $stored : [] as $provider => $percent) {
            if (is_string($provider) && (is_int($percent) || is_float($percent)) && $percent >= 0 && $percent <= 30) {
                $result[$provider] = (float) $percent;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $percents provider => text typed in the form ("2,8"); empty or invalid entries are dropped
     * @return array<string, float> what was stored
     */
    public function saveFees(array $percents, ?int $actorId): array
    {
        $clean = [];
        foreach ($percents as $provider => $text) {
            $number = is_string($text) ? str_replace(',', '.', trim($text)) : '';
            if (preg_match('/^[a-z]{3,12}$/', $provider) === 1 && preg_match('/^\d{1,2}(\.\d{1,3})?$/', $number) === 1 && (float) $number <= 30) {
                $clean[$provider] = (float) $number;
            }
        }
        if ($clean === []) {
            $this->settings->forget(self::SETTING);
        } else {
            $this->settings->set(self::SETTING, $clean, $actorId);
        }

        return $clean;
    }

    /**
     * Providers that have payments (for the fee form).
     *
     * @return list<string>
     */
    public function providers(): array
    {
        return array_map(static fn (array $r): string => (string) $r['provider'], $this->db->select('SELECT DISTINCT provider FROM payments ORDER BY provider'));
    }

    /**
     * @param DateTimeImmutable $to exclusive end
     * @return \Generator<int, list<scalar|\DateTimeInterface|null>>
     */
    public function rows(DateTimeImmutable $from, DateTimeImmutable $to): \Generator
    {
        $fees = $this->fees();
        $offset = 0;
        while ($offset < self::LIMIT) {
            $rows = $this->db->select(
                'SELECT i.paid_at, i.number, i.kind, i.period, i.amount, i.currency, i.customer_email, p.name AS plan, w.public_id AS workspace, pay.provider, pay.provider_payment_id, pay.status, pay.refunded_amount '
                . 'FROM payments pay JOIN invoices i ON i.id = pay.invoice_id JOIN plans p ON p.id = i.plan_id JOIN workspaces w ON w.id = pay.workspace_id '
                . "WHERE pay.status IN ('succeeded', 'refunded') AND i.paid_at >= ? AND i.paid_at < ? ORDER BY i.paid_at, pay.id LIMIT 1000 OFFSET " . $offset,
                [DbTime::format($from), DbTime::format($to)],
            );
            if ($rows === []) {
                return;
            }
            foreach ($rows as $row) {
                $amount = (int) $row['amount'];
                $percent = $fees[(string) $row['provider']] ?? null;
                $fee = $percent === null ? null : (int) round($amount * $percent / 100);
                yield [
                    DbTime::parse($row['paid_at']),
                    $row['number'],
                    $row['plan'],
                    $row['kind'],
                    $row['period'],
                    $row['workspace'],
                    $row['customer_email'],
                    self::decimal($amount),
                    $row['currency'],
                    $row['provider'],
                    $row['provider_payment_id'],
                    $row['status'] === 'refunded' ? 'возвращён' : ((int) $row['refunded_amount'] > 0 ? 'частично возвращён' : 'оплачен'),
                    self::decimal((int) $row['refunded_amount']),
                    $fee === null ? null : self::decimal($fee),
                    $fee === null ? null : self::decimal($amount - (int) $row['refunded_amount'] - $fee),
                    $row['number'],
                ];
            }
            $offset += 1000;
        }
    }

    /**
     * @return list<string>
     */
    public static function header(): array
    {
        return ['Дата оплаты (UTC)', 'Номер счёта', 'Тариф', 'Вид', 'Срок', 'Пространство', 'Почта плательщика', 'Сумма', 'Валюта', 'Провайдер', 'Номер у провайдера', 'Состояние', 'Возвращено', 'Комиссия (оценка)', 'К получению (оценка)', 'Чек (номер счёта)'];
    }

    private static function decimal(int $minor): string
    {
        return number_format($minor / 100, 2, ',', '');
    }
}
