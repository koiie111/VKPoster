<?php

declare(strict_types=1);

namespace App\Domain\Automation\Import;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use Throwable;

/**
 * Reads an uploaded CSV or XLSX file into rows of text cells. Formulas are never calculated (a cell is read as the text it holds),
 * the number of rows and columns is capped before they are loaded, and anything unreadable becomes a message for the person.
 */
final class SheetReader implements IReadFilter
{
    public const MAX_ROWS = 501;
    public const MAX_COLUMNS = 8;
    public const MAX_BYTES = 2 * 1024 * 1024;

    /**
     * @return list<list<string>> every row padded to the same width; the first row is the header
     * @throws ImportException
     */
    public static function read(string $path, string $filename): array
    {
        $size = is_file($path) ? filesize($path) : false;
        if ($size === false || $size === 0) {
            throw new ImportException('Файл пустой. Скачайте шаблон, заполните его и загрузите снова.');
        }
        if ($size > self::MAX_BYTES) {
            throw new ImportException('Файл больше 2 МБ. Разбейте его на несколько частей.');
        }
        $head = (string) file_get_contents($path, false, null, 0, 4);
        $rows = str_starts_with($head, "PK\x03\x04") || str_ends_with(strtolower($filename), '.xlsx') ? self::xlsx($path) : self::csv($path);

        $rows = array_values(array_filter($rows, static fn (array $row): bool => array_filter($row, static fn (string $c): bool => trim($c) !== '') !== []));
        if (count($rows) < 2) {
            throw new ImportException('В файле нет ни одной строки с постом. Заполните шаблон и загрузите снова.');
        }
        if (count($rows) > self::MAX_ROWS) {
            throw new ImportException(sprintf('В файле больше %d постов. Разбейте его на несколько частей.', self::MAX_ROWS - 1));
        }

        return $rows;
    }

    public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
    {
        return $row <= self::MAX_ROWS + 50 && \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($columnAddress) <= self::MAX_COLUMNS;
    }

    /**
     * @return list<list<string>>
     */
    private static function csv(string $path): array
    {
        $raw = (string) file_get_contents($path);
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1251');
        }
        $first = strtok($raw, "\n") ?: '';
        $delimiter = substr_count($first, ';') >= substr_count($first, ',') && substr_count($first, ';') > 0 ? ';' : (substr_count($first, "\t") > 0 ? "\t" : ',');
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new ImportException('Не удалось прочитать файл.');
        }
        fwrite($stream, $raw);
        rewind($stream);
        $rows = [];
        while (($cells = fgetcsv($stream, 0, $delimiter, '"', '\\')) !== false && count($rows) <= self::MAX_ROWS + 50) {
            $rows[] = array_map(static fn (?string $c): string => (string) $c, array_slice($cells, 0, self::MAX_COLUMNS));
        }
        fclose($stream);

        return $rows;
    }

    /**
     * @return list<list<string>>
     */
    private static function xlsx(string $path): array
    {
        try {
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadFilter(new self());
            $sheet = $reader->load($path)->getActiveSheet();
            $rows = [];
            foreach ($sheet->getRowIterator(1, self::MAX_ROWS + 50) as $row) {
                $cells = [];
                foreach ($row->getCellIterator('A', 'H') as $cell) {
                    $cells[] = self::cellText($cell);
                }
                $rows[] = $cells;
            }

            return $rows;
        } catch (Throwable) {
            throw new ImportException('Не удалось прочитать файл Excel. Сохраните таблицу как XLSX или CSV и загрузите снова.');
        }
    }

    private static function cellText(Cell $cell): string
    {
        // The formatted value shows a date as typed ("14.10.2026") and never evaluates a formula: a formula cell is read as its text.
        $value = $cell->isFormula() ? (string) $cell->getValue() : $cell->getFormattedValue();

        return trim($value);
    }
}
