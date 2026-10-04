<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dev;

use DateTimeImmutable;

/**
 * Static demo content for the design-system showcase and the clickable prototypes (`/dev/*`).
 * Dates are fixed on purpose: prototypes must look the same on every run and in screenshots.
 */
final class DemoData
{
    /**
     * @return list<array{title: string, text: string, platforms: list<string>, status: string, time: string, error?: string}>
     */
    public static function posts(): array
    {
        return [
            ['title' => 'Осенняя коллекция уже в продаже', 'text' => 'Расскажем, чем она отличается от прошлогодней, и покажем три образа на каждый день.', 'platforms' => ['vk', 'tg'], 'status' => 'scheduled', 'time' => 'Чт, 9 окт, 12:30 (МСК)'],
            ['title' => 'Итоги недели: 120 новых подписчиков', 'text' => 'Спасибо, что вы с нами! Делимся цифрами и планами на следующую неделю.', 'platforms' => ['tg', 'max'], 'status' => 'published', 'time' => 'Пн, 6 окт, 09:00 (МСК)'],
            ['title' => 'Розыгрыш к началу сезона', 'text' => 'Условия участия и призы в карусели из пяти фото.', 'platforms' => ['ig'], 'status' => 'failed', 'time' => 'Вт, 7 окт, 18:00 (МСК)', 'error' => 'Instagram не принял картинку: она слишком узкая. Замените фото и повторите.'],
            ['title' => 'Новое меню: тыквенный латте', 'text' => 'Черновик с идеями для сезонного меню, пока без фото.', 'platforms' => ['vk'], 'status' => 'draft', 'time' => 'Время не выбрано'],
        ];
    }

    /**
     * @return list<array{label: string, num: int, today: bool, items: list<array{platform: string, time: string, title: string, status: string}>}>
     */
    public static function week(): array
    {
        $items = [
            0 => [['vk', '09:00', 'Итоги недели', 'published'], ['tg', '09:00', 'Итоги недели', 'published']],
            1 => [['ig', '18:00', 'Розыгрыш к сезону', 'failed']],
            2 => [['vk', '12:30', 'Осенняя коллекция', 'scheduled'], ['tg', '12:30', 'Осенняя коллекция', 'scheduled']],
            4 => [['tg', '10:00', 'Опрос: любимый напиток', 'scheduled']],
            6 => [['max', '20:00', 'Анонс мастер-класса', 'draft']],
        ];
        $labels = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
        $days = [];
        foreach ($labels as $i => $label) {
            $days[] = [
                'label' => $label,
                'num' => 5 + $i,
                'today' => $i === 2,
                'items' => array_map(self::item(...), $items[$i] ?? []),
            ];
        }

        return $days;
    }

    /**
     * October 2026 as weeks of seven cells (Monday first).
     *
     * @return list<list<array{num: int, other: bool, today: bool, items: list<array{platform: string, time: string, title: string, status: string}>}>>
     */
    public static function month(): array
    {
        $byDay = [
            1 => [['vk', '10:00', 'Новое меню', 'published']],
            3 => [['tg', '12:00', 'Субботний розыгрыш', 'published'], ['ig', '12:00', 'Субботний розыгрыш', 'published']],
            5 => [['vk', '09:00', 'Итоги недели', 'published'], ['tg', '09:00', 'Итоги недели', 'published']],
            6 => [['ig', '18:00', 'Розыгрыш к сезону', 'failed']],
            7 => [['vk', '12:30', 'Осенняя коллекция', 'scheduled'], ['tg', '12:30', 'Осенняя коллекция', 'scheduled'], ['max', '15:00', 'Скидки недели', 'scheduled']],
            9 => [['tg', '10:00', 'Опрос: любимый напиток', 'scheduled']],
            11 => [['max', '20:00', 'Анонс мастер-класса', 'draft']],
            14 => [['vk', '11:00', 'История бренда', 'scheduled']],
            16 => [['ig', '19:00', 'Закулисье', 'scheduled'], ['tg', '19:00', 'Закулисье', 'scheduled']],
            21 => [['vk', '12:00', 'Акция выходного дня', 'draft']],
            28 => [['tg', '09:00', 'Итоги месяца', 'draft']],
        ];
        $first = new DateTimeImmutable('2026-10-01');
        $start = $first->modify('monday this week');
        $weeks = [];
        for ($w = 0; $w < 5; $w++) {
            $row = [];
            for ($d = 0; $d < 7; $d++) {
                $date = $start->modify('+' . ($w * 7 + $d) . ' days');
                $inMonth = $date->format('Y-m') === '2026-10';
                $num = (int) $date->format('j');
                $row[] = [
                    'num' => $num,
                    'other' => !$inMonth,
                    'today' => $inMonth && $num === 7,
                    'items' => $inMonth ? array_values(array_map(self::item(...), $byDay[$num] ?? [])) : [],
                ];
            }
            $weeks[] = $row;
        }

        return $weeks;
    }

    /**
     * @return list<array{label: string, items: list<array{platform: string, time: string, title: string, status: string}>}>
     */
    public static function listGroups(): array
    {
        return [
            ['label' => 'Сегодня, 7 октября', 'items' => [self::item(['vk', '12:30', 'Осенняя коллекция уже в продаже', 'scheduled']), self::item(['tg', '12:30', 'Осенняя коллекция уже в продаже', 'scheduled']), self::item(['max', '15:00', 'Скидки недели', 'scheduled'])]],
            ['label' => 'Пятница, 9 октября', 'items' => [self::item(['tg', '10:00', 'Опрос: любимый напиток', 'scheduled'])]],
            ['label' => 'Воскресенье, 11 октября', 'items' => [self::item(['max', '20:00', 'Анонс мастер-класса', 'draft'])]],
        ];
    }

    /**
     * @return list<array{id: string, platform: string, name: string, handle: string, status: string, note: string}>
     */
    public static function channels(): array
    {
        return [
            ['id' => 'c1', 'platform' => 'tg', 'name' => 'Кофейня «Зерно»', 'handle' => '@zerno_coffee', 'status' => 'connected', 'note' => 'Последний пост: вчера, 12:30'],
            ['id' => 'c2', 'platform' => 'vk', 'name' => 'Зерно | Кофейня на Арбате', 'handle' => 'vk.com/zerno_arbat', 'status' => 'connected', 'note' => 'Последний пост: 3 дня назад'],
            ['id' => 'c3', 'platform' => 'ig', 'name' => 'zerno.coffee', 'handle' => 'Instagram', 'status' => 'needs_reauth', 'note' => 'Доступ истёк 1 октября. Переподключите канал, иначе посты не уйдут.'],
            ['id' => 'c4', 'platform' => 'max', 'name' => 'Зерно: новости', 'handle' => 'MAX', 'status' => 'paused', 'note' => 'Публикации приостановлены вами'],
        ];
    }

    /**
     * @param array{0: string, 1: string, 2: string, 3: string} $row
     * @return array{platform: string, time: string, title: string, status: string}
     */
    private static function item(array $row): array
    {
        return ['platform' => $row[0], 'time' => $row[1], 'title' => $row[2], 'status' => $row[3]];
    }
}
