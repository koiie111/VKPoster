<?php

declare(strict_types=1);

namespace App\Domain\Content;

use App\Kernel\Config;
use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;

/**
 * The editable texts of the landing page: named blocks (`hero.title`, ...) and the FAQ. A block with no saved value shows the text written
 * in the template, so nothing is ever blank by accident; saving an empty value returns a block to the written text.
 * All values are plain text (escaped when printed).
 */
final class SiteContent
{
    /** @var array<string, array{label: string, default: string, long: bool}> block => what it is, the written text, whether it is a paragraph */
    public const BLOCKS = [
        'hero.title' => ['label' => 'Главный заголовок', 'default' => 'Пишите посты заранее. Публикуем вовремя.', 'long' => false],
        'hero.text' => ['label' => 'Текст под заголовком', 'default' => 'Один календарь для всех ваших каналов и сообществ. Напишите пост один раз, выберите время, и {app_name} опубликует его сам, а если что-то пойдёт не так, сообщит вам.', 'long' => true],
        'hero.note' => ['label' => 'Подпись под кнопками', 'default' => 'Без банковской карты. После пробного периода останется бесплатный тариф.', 'long' => false],
        'features.title' => ['label' => 'Заголовок «Возможности»', 'default' => 'Всё, чтобы посты выходили без вашего участия', 'long' => false],
        'how.title' => ['label' => 'Заголовок «Как это работает»', 'default' => 'Три шага до первого поста', 'long' => false],
        'pricing.title' => ['label' => 'Заголовок «Тарифы»', 'default' => 'Тарифы', 'long' => false],
        'faq.title' => ['label' => 'Заголовок «Вопросы и ответы»', 'default' => 'Вопросы и ответы', 'long' => false],
        'cta.title' => ['label' => 'Призыв в конце страницы', 'default' => 'Попробуйте первый пост сегодня', 'long' => false],
    ];

    public const FAQ = 'faq';
    public const MAX_FAQ = 20;

    /** @var array<string, string>|null */
    private ?array $values = null;

    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly Config $config)
    {
    }

    /**
     * The text of a block: the saved one, or the one given as the default (the template's own).
     */
    public function text(string $name, string $default = ''): string
    {
        $value = $this->all()[$name] ?? '';
        $text = $value !== '' ? $value : ($default !== '' ? $default : (self::BLOCKS[$name]['default'] ?? ''));

        // `{app_name}` is the name of the service, so a text does not have to be rewritten when the product is renamed.
        return str_replace('{app_name}', $this->config->string('app.name'), $text);
    }

    /**
     * @param array<string, string> $texts block => text; unknown blocks are ignored, empty text restores the written one
     */
    public function saveTexts(array $texts, ?int $actorId): void
    {
        foreach (self::BLOCKS as $name => $block) {
            if (!array_key_exists($name, $texts)) {
                continue;
            }
            $this->store($name, mb_substr(trim($texts[$name]), 0, $block['long'] ? 600 : 160), $actorId);
        }
        $this->values = null;
    }

    /**
     * The saved questions, or null when the file is used.
     *
     * @return list<array{0: string, 1: string}>|null
     */
    public function faq(): ?array
    {
        $json = $this->all()[self::FAQ] ?? '';
        $decoded = $json === '' ? null : json_decode($json, true);
        if (!is_array($decoded)) {
            return null;
        }
        $items = [];
        foreach ($decoded as $pair) {
            if (is_array($pair) && is_string($pair[0] ?? null) && is_string($pair[1] ?? null)) {
                $items[] = [$pair[0], $pair[1]];
            }
        }

        return $items;
    }

    /**
     * Save the FAQ as typed: pairs with an empty question or answer are dropped; nothing left restores the file's questions.
     *
     * @param list<array{0: string, 1: string}> $pairs
     * @return int how many questions were saved
     */
    public function saveFaq(array $pairs, ?int $actorId): int
    {
        $clean = [];
        foreach ($pairs as $pair) {
            $q = mb_substr(trim($pair[0]), 0, 200);
            $a = mb_substr(trim($pair[1]), 0, 1200);
            if ($q !== '' && $a !== '') {
                $clean[] = [$q, $a];
            }
            if (count($clean) >= self::MAX_FAQ) {
                break;
            }
        }
        $this->store(self::FAQ, $clean === [] ? '' : json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $actorId);
        $this->values = null;

        return count($clean);
    }

    /**
     * Saved values by block (only the ones that differ from "use the written text").
     *
     * @return array<string, string>
     */
    public function saved(): array
    {
        return $this->all();
    }

    /**
     * @return array<string, string>
     */
    private function all(): array
    {
        if ($this->values === null) {
            $this->values = [];
            foreach ($this->db->select('SELECT name, value FROM cms_blocks') as $row) {
                $this->values[(string) $row['name']] = (string) $row['value'];
            }
        }

        return $this->values;
    }

    private function store(string $name, string $value, ?int $actorId): void
    {
        if ($value === '') {
            $this->db->execute('DELETE FROM cms_blocks WHERE name = ?', [$name]);

            return;
        }
        $this->db->execute(
            'INSERT INTO cms_blocks (name, value, updated_by, updated_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)',
            [$name, $value, $actorId, DbTime::format($this->clock->now())],
        );
    }
}
