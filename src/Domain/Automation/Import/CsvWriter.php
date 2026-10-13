<?php

declare(strict_types=1);

namespace App\Domain\Automation\Import;

/**
 * Writes CSV that is safe to open in a spreadsheet: a cell that starts with `=`, `+`, `-`, `@`, a tab or a carriage return would be
 * run as a formula (`=cmd|' /C calc'!A0`), so such cells get a leading apostrophe and stay text. Semicolon-separated with a BOM,
 * which is what Excel with a Russian locale opens correctly.
 */
final class CsvWriter
{
    /**
     * @param list<list<string|int|null>> $rows
     */
    public static function render(array $rows, string $delimiter = ';'): string
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            return '';
        }
        fwrite($stream, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($stream, array_map(static fn (string|int|null $cell): string => self::cell((string) $cell), $row), $delimiter, '"', '\\', "\r\n");
        }
        rewind($stream);
        $out = (string) stream_get_contents($stream);
        fclose($stream);

        return $out;
    }

    public static function cell(string $value): string
    {
        return $value !== '' && strpbrk($value[0], "=+-@\t\r") !== false ? "'" . $value : $value;
    }
}
