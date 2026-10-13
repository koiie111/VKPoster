<?php

declare(strict_types=1);

namespace App\Domain\Automation\Import;

use DateTimeImmutable;

/**
 * Turns the rows of an import file into checked `ImportRow`s. Columns are found by their header (Russian or English, any order):
 * date, time, channels, text, media. Dates may be written 2026-10-14 or 14.10.2026, times 10:00 or 10.00. Channels are matched by
 * the name shown in the service or by id; several are separated by `;` or `,`. Text is taken literally: a cell that looks like a
 * formula is just text here.
 */
final class ImportParser
{
    private const HEADERS = [
        'date' => ['дата', 'date'],
        'time' => ['время', 'time'],
        'channels' => ['каналы', 'канал', 'channels', 'channel'],
        'text' => ['текст', 'text'],
        'media' => ['медиа', 'файлы', 'media', 'files'],
    ];
    public const MAX_TEXT = 20000;
    public const MAX_MEDIA = 10;

    /**
     * @param list<list<string>> $rows the first row is the header
     * @param array<string, array{id: string, names: list<string>}> $channels by lower-cased name or id → channel
     * @return list<ImportRow>
     * @throws ImportException when the header does not have the needed columns
     */
    public static function parse(array $rows, array $channels, bool $needsDate, DateTimeImmutable $now, string $timezone): array
    {
        $columns = self::columns(array_shift($rows) ?? []);
        if (!isset($columns['channels'], $columns['text'])) {
            throw new ImportException('В первой строке должны быть названия столбцов: «Дата», «Время», «Каналы», «Текст», «Медиа». Скачайте шаблон, чтобы не ошибиться.');
        }
        $result = [];
        foreach ($rows as $i => $cells) {
            $line = $i + 2;
            $get = static fn (string $key): string => isset($columns[$key]) ? trim($cells[$columns[$key]] ?? '') : '';
            $errors = [];

            $ids = [];
            $names = [];
            foreach (preg_split('/[;,\n]+/u', $get('channels'), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $name) {
                $channel = $channels[mb_strtolower(trim($name))] ?? null;
                if ($channel === null) {
                    $errors[] = 'Канал «' . mb_substr(trim($name), 0, 60) . '» не найден среди ваших каналов.';
                } elseif (!in_array($channel['id'], $ids, true)) {
                    $ids[] = $channel['id'];
                    $names[] = $channel['names'][0] ?? $channel['id'];
                }
            }
            if ($ids === [] && $errors === []) {
                $errors[] = 'Укажите хотя бы один канал.';
            }

            $text = $get('text');
            $media = array_values(array_filter(preg_split('/[\s;]+/u', $get('media'), -1, PREG_SPLIT_NO_EMPTY) ?: []));
            if (mb_strlen($text) > self::MAX_TEXT) {
                $errors[] = 'Текст длиннее ' . self::MAX_TEXT . ' символов.';
            }
            if (trim($text) === '' && $media === []) {
                $errors[] = 'Нужен текст или файл.';
            }
            foreach ($media as $url) {
                if (preg_match('~^https?://[^\s]{1,1900}$~i', $url) !== 1) {
                    $errors[] = 'Ссылка на файл «' . mb_substr($url, 0, 60) . '» не похожа на адрес в интернете (нужно http:// или https://).';
                    break;
                }
            }
            if (count($media) > self::MAX_MEDIA) {
                $errors[] = 'Файлов может быть не больше ' . self::MAX_MEDIA . '.';
            }

            [$date, $time] = self::when($get('date'), $get('time'), $needsDate, $errors, $now, $timezone);
            $result[] = new ImportRow($line, $date, $time, $ids, $names, $text, array_slice($media, 0, self::MAX_MEDIA), $errors);
        }

        return $result;
    }

    /**
     * @param list<string> $header
     * @return array<string, int> field → column index
     */
    private static function columns(array $header): array
    {
        $found = [];
        foreach ($header as $index => $title) {
            $title = mb_strtolower(trim(ltrim($title, "\xEF\xBB\xBF'")));
            foreach (self::HEADERS as $key => $names) {
                if (in_array($title, $names, true) && !isset($found[$key])) {
                    $found[$key] = $index;
                }
            }
        }

        return $found;
    }

    /**
     * @param list<string> $errors
     * @return array{?string, ?string}
     */
    private static function when(string $date, string $time, bool $needsDate, array &$errors, DateTimeImmutable $now, string $timezone): array
    {
        if ($date === '' && $time === '') {
            if ($needsDate) {
                $errors[] = 'Укажите дату и время публикации.';
            }

            return [null, null];
        }
        $normalDate = self::date($date);
        $normalTime = self::time($time);
        if ($normalDate === null) {
            $errors[] = 'Дата «' . mb_substr($date, 0, 30) . '» не понятна. Напишите её как 14.10.2026 или 2026-10-14.';
        }
        if ($normalTime === null) {
            $errors[] = 'Время «' . mb_substr($time, 0, 30) . '» не понятно. Напишите его как 10:00.';
        }
        if ($normalDate !== null && $normalTime !== null) {
            try {
                \App\Domain\Post\ScheduleTime::parse($normalDate, $normalTime, $timezone, $now);
            } catch (\App\Domain\Post\PostException $e) {
                if ($needsDate || !str_contains($e->getMessage(), 'прошло')) {
                    $errors[] = $e->getMessage();
                }
            }
        }

        return [$normalDate, $normalTime];
    }

    private static function date(string $value): ?string
    {
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $m) === 1) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})[.\/](\d{1,2})[.\/](\d{4})$/', $value, $m) === 1) {
            [$y, $mo, $d] = [(int) $m[3], (int) $m[2], (int) $m[1]];
        } else {
            return null;
        }

        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    private static function time(string $value): ?string
    {
        if (preg_match('/^(\d{1,2})[:.](\d{2})(?::\d{2})?$/', $value, $m) !== 1 || (int) $m[1] > 23 || (int) $m[2] > 59) {
            return null;
        }

        return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
    }
}
