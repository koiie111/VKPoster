<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Builds CSV that Excel opens correctly in a Russian locale: UTF-8 with a byte-order mark, `;` as the separator, CRLF line ends.
 * A cell that starts with `=`, `+`, `-`, `@`, tab or CR is prefixed with an apostrophe so a spreadsheet never runs it as a formula
 * (CSV injection through names and messages that people typed).
 */
final class Csv
{
    /**
     * @param list<string> $header
     * @param iterable<list<scalar|\DateTimeInterface|null>> $rows
     */
    public static function build(array $header, iterable $rows): string
    {
        $out = "\xEF\xBB\xBF" . self::line($header);
        foreach ($rows as $row) {
            $out .= self::line($row);
        }

        return $out;
    }

    /**
     * @param list<scalar|\DateTimeInterface|null> $cells
     */
    public static function line(array $cells): string
    {
        $parts = [];
        foreach ($cells as $cell) {
            $text = match (true) {
                $cell === null => '',
                $cell instanceof \DateTimeInterface => $cell->format('Y-m-d H:i:s'),
                is_bool($cell) => $cell ? '1' : '0',
                default => (string) $cell,
            };
            if ($text !== '' && strpbrk($text[0], "=+-@\t\r") !== false && !is_numeric($text)) {
                $text = "'" . $text;
            }
            $parts[] = '"' . str_replace('"', '""', $text) . '"';
        }

        return implode(';', $parts) . "\r\n";
    }
}
