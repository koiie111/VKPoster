<?php

declare(strict_types=1);

namespace App\Tests\Unit\Analytics;

use App\Domain\Analytics\{PostAnalytics,PostMetrics,AnalyticsCsv};
use PHPUnit\Framework\TestCase;

final class PostAnalyticsTest extends TestCase
{
    public function testErUsesMatchedReachAndUnknownIsNotZero(): void
    {
        $m = new PostMetrics(views:100, reach:80, engagement:8);
        self::assertSame(10.0, $m->er());
        self::assertNull((new PostMetrics(engagement:0))->er());
        $known = ['metrics' => $m->toArray()];
        $zero = ['metrics' => (new PostMetrics(0, 0, 0))->toArray()];
        $unknown = ['metrics' => (new PostMetrics())->toArray()];
        self::assertSame(0, PostAnalytics::aggregate([$zero])['engagement']);
        self::assertNull(PostAnalytics::aggregate([$known,$unknown])['engagement']);
        self::assertSame(1, PostAnalytics::aggregate([$known,$unknown])['coverage']['engagement']);
        self::assertSame(10.0, PostAnalytics::aggregate([$known,$zero])['er']);
    }
    public function testBestTimeRequiresEnoughSamplesAndShowsTransparentCounts(): void
    {
        $p = ['weekday' => 2,'hour' => 10,'metrics' => ['engagement' => 8]];
        self::assertNull(PostAnalytics::bestTime(array_fill(0, 19, $p))['recommendation']);
        $best = PostAnalytics::bestTime(array_fill(0, 20, $p))['recommendation'];
        self::assertSame(2, $best['day']);
        self::assertSame(10, $best['hour']);
        self::assertSame(8.0, $best['average']);
        $sparse = [];
        for ($i = 0;$i < 24;++$i) {
            $q = $p;
            $q['hour'] = $i;
            $sparse[] = $q;
        }
        self::assertNull(PostAnalytics::bestTime($sparse)['recommendation']);
    }
    public function testCsvNeutralizesSpreadsheetFormulasWithoutLosingUnicode(): void
    {
        foreach (['=SUM(A1)'," +cmd",'@evil','-1',"\t=2", "\rtext"] as $bad) {
            self::assertSame("'".$bad, AnalyticsCsv::cell($bad));
        }
        self::assertSame('Привет', AnalyticsCsv::cell('Привет'));
        self::assertSame('0',AnalyticsCsv::cell('0'));
    }
}
