<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

/** UTF-8 CSV with spreadsheet formula neutralization, including leading controls and whitespace. */
final class AnalyticsCsv
{
    public static function cell(string $value): string
    {
        return preg_match('/^[\p{Z}\s\x00-\x1f]*[=+@-]/u', $value) === 1 || preg_match('/^[\t\r\n]/', $value) === 1 ? "'".$value : $value;
    }
    /** @param array<string,mixed> $report */
    public static function render(array $report): string
    {
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new \RuntimeException('Report unavailable.');
        }
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, ['Дата','Канал','Платформа','Текст','Просмотры','Охват','Вовлечённость','ER %'], ';', '"', '');
        foreach ($report['posts'] as $p) {
            $row = [$p['sent_at'],
                $p['title'],
                $p['platform'],
                $p['text'],
                $p['metrics']['views'] ?? 'Нет данных',
                $p['metrics']['reach'] ?? 'Нет данных',
                $p['metrics']['engagement'] ?? 'Нет данных',
                $p['er'] ?? 'Нет данных'];
            fputcsv($stream, array_map(static fn ($v): string => self::cell((string)$v), $row), ';', '"', '');
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        return $csv === false ? '' : $csv;
    }
}
