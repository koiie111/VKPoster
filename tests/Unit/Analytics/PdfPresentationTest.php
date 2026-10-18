<?php

declare(strict_types=1);

namespace App\Tests\Unit\Analytics;

use App\Domain\Analytics\ReportExcerpt;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class PdfPresentationTest extends TestCase
{
    public function testExcerptsKeepShortTextAndSignalOmissionAtAWordBoundary(): void
    {
        self::assertSame('Короткий пост.', ReportExcerpt::fromText('Короткий пост.'));
        $exact = str_repeat('я', 200);
        self::assertSame($exact, ReportExcerpt::fromText($exact));
        $long = str_repeat('Важная публикация о технологиях. ', 20);
        $excerpt = ReportExcerpt::fromText(mb_substr($long, 0, 201));
        self::assertLessThanOrEqual(200, mb_strlen($excerpt));
        self::assertStringEndsWith('…', $excerpt);
        self::assertSame(1, preg_match('/[\s.]$/u', mb_substr($long, mb_strlen($excerpt) - 1, 1)));
        self::assertSame('…', ReportExcerpt::fromText(str_repeat('я', 201)));
        self::assertSame('Первая строка…', ReportExcerpt::fromText("Первая строка\n" . str_repeat('я', 210)));
    }

    public function testTemplateKeepsUnavailableCyrillicAndLatestSubscriberSnapshot(): void
    {
        $html = $this->render(self::report(2));
        self::assertStringContainsString('Короткий пост.', $html);
        self::assertStringContainsString('Нет данных', $html);
        self::assertStringContainsString('Подписчики', $html);
        self::assertStringContainsString('<td>9</td>', $html);
        self::assertStringNotContainsString('<td>7</td>', $html);
        self::assertStringContainsString('2026-10-18 19:29', $html);
        self::assertStringContainsString('2026-10-18', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testPrintLayoutSupportsLongNamesMultipleRowsAndRepeatedHeaders(): void
    {
        $html = $this->render(self::report(40));
        self::assertStringContainsString('width: 44%', $html);
        self::assertStringContainsString('table-layout: fixed', $html);
        self::assertStringContainsString('table-header-group', $html);
        self::assertStringContainsString('break-inside: avoid', $html);
        self::assertStringContainsString('overflow-wrap: anywhere', $html);
        self::assertStringNotContainsString('overflow: hidden', $html);
        self::assertStringNotContainsString('height:', $html);
        self::assertSame(40, substr_count($html, 'class="publication"'));
        self::assertStringContainsString('Ряд 40', $html);
    }

    public function testGuestReportDoesNotInventSubscriberDataAndNullIsNotZero(): void
    {
        $report = self::report(1);
        $report['followers'] = [];
        $html = $this->render($report);
        self::assertStringNotContainsString('<h2>Подписчики</h2>', $html);
        $report['followers'] = [['channel_id' => 1, 'title' => 'Канал', 'observed_at' => '2026-10-18 19:29:00', 'followers' => null]];
        self::assertStringContainsString('<td>Нет данных</td>', $this->render($report));
        $report['followers'][0]['followers'] = 0;
        self::assertStringContainsString('<td>0</td>', $this->render($report));
    }

    /** Synthetic fixtures also exercise the real Chromium renderer without production content or keys.
     * @return array<string,mixed>
     */
    public static function report(int $count): array
    {
        $posts = [];
        for ($i = 1; $i <= $count; ++$i) {
            $text = $i === 1 ? 'Короткий пост.' : 'Ряд ' . $i . '. ' . str_repeat('Это длинная публикация о важных событиях и технологиях. ', 20);
            $posts[] = ['local_date' => '2026-10-18', 'title' => 'Длинное название канала о психологии, технологиях и важных событиях', 'excerpt' => ReportExcerpt::fromText($text), 'metrics' => ['reach' => null, 'engagement' => null]];
        }
        return ['from' => '2026-10-12', 'to' => '2026-10-18', 'timezone' => 'Europe/Moscow', 'totals' => ['publications' => $count, 'reach' => null, 'er' => null], 'posts' => $posts, 'followers' => [
            ['channel_id' => 1, 'title' => '<script>Канал</script>', 'observed_at' => '2026-10-17 10:00:00', 'followers' => 7],
            ['channel_id' => 1, 'title' => '<script>Канал</script>', 'observed_at' => '2026-10-18 19:29:00', 'followers' => 9],
        ]];
    }

    /** @param array<string,mixed> $report */
    private function render(array $report): string
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/templates'), ['autoescape' => 'html', 'strict_variables' => true]);
        return $twig->render('workspace/analytics/print.twig', ['report' => $report]);
    }
}
