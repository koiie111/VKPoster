<?php

declare(strict_types=1);

namespace App\Tests\Integration\Admin;

use App\Domain\Admin\DemoData;
use App\Domain\Analytics\MetricsAggregator;
use App\Domain\Billing\PaymentRepository;
use App\Tests\Support\BillingTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The demo generator makes a consistent, repeatable data set, only touches what it made, and the aggregation reads it back.
 */
#[CoversClass(DemoData::class)]
final class DemoDataTest extends BillingTestCase
{
    private function demo(): DemoData
    {
        return new DemoData($this->db, $this->clock, $this->app->container()->get(PaymentRepository::class), $this->app->container()->get(MetricsAggregator::class));
    }

    public function testGeneratesPurgesAndRepeats(): void
    {
        [$real] = $this->ownerWithWorkspace('real@example.com');
        $summary = $this->demo()->generate(40, 60, 7);
        self::assertSame(40, $summary['users']);
        self::assertSame(40, (int) $this->db->select('SELECT COUNT(*) AS c FROM users WHERE email LIKE ?', ['%@' . DemoData::DOMAIN])[0]['c']);
        self::assertGreaterThan(0, $summary['channels']);
        self::assertGreaterThan(0, $summary['publications']);
        self::assertGreaterThan(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM metrics_daily')[0]['c']);

        // Money journal: every successful demo payment has its entries and each transaction sums to zero.
        self::assertSame(0, (int) $this->db->select('SELECT COALESCE(SUM(amount), 0) AS s FROM ledger_entries')[0]['s']);

        self::assertSame(40, $this->demo()->purge());
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM users WHERE email LIKE ?', ['%@' . DemoData::DOMAIN])[0]['c']);
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM payments')[0]['c']);
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM ledger_entries')[0]['c']);
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS c FROM users WHERE id = ?', [$real->id])[0]['c'], 'real accounts stay');

        $again = $this->demo()->generate(40, 60, 7);
        self::assertSame($summary, $again, 'the same seed makes the same data');
    }
}
