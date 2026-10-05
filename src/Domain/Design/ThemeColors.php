<?php

declare(strict_types=1);

namespace App\Domain\Design;

use App\Domain\Settings\Settings;

/**
 * The colours of the site, editable by hand in the admin area. The design system keeps every colour in a CSS variable holding an
 * `R G B` triplet (`resources/css/app.css`); this class knows the standard values (a test keeps them equal to that file), stores only what
 * the owner changed (`app_settings` name `design.colors`: `{light: {token: "R G B"}, dark: {...}}`) and renders the override as CSS served
 * from `/theme.css`, which is loaded after the built stylesheet. Nothing here can break the site for good: "reset" deletes the setting and
 * the standard colours are back.
 *
 * The CSP forbids inline styles, which is why the override is a separate file and not a `<style>` block.
 */
final class ThemeColors
{
    public const SETTING = 'design.colors';

    /** @var array<string, array{0: string, 1: string}> token => [label, group] (order is the order of the form) */
    public const TOKENS = [
        'bg' => ['Фон страницы', 'Основа'],
        'surface' => ['Фон карточек и панелей', 'Основа'],
        'sunken' => ['Углублённый фон (подложки, поля)', 'Основа'],
        'line' => ['Линии и границы', 'Основа'],
        'fg' => ['Основной текст', 'Основа'],
        'muted' => ['Вторичный текст', 'Основа'],
        'p' => ['Акцент: кнопки и выделение', 'Акцент'],
        'p-hover' => ['Акцент при наведении', 'Акцент'],
        'p-fg' => ['Текст на акцентной кнопке', 'Акцент'],
        'p-soft' => ['Светлый акцент (фон меток)', 'Акцент'],
        'p-softfg' => ['Текст на светлом акценте', 'Акцент'],
        'p-text' => ['Ссылки и акцентный текст', 'Акцент'],
        'ok' => ['Успех: цвет', 'Статусы'],
        'ok-soft' => ['Успех: фон', 'Статусы'],
        'ok-fg' => ['Успех: текст', 'Статусы'],
        'warn' => ['Предупреждение: цвет', 'Статусы'],
        'warn-soft' => ['Предупреждение: фон', 'Статусы'],
        'warn-fg' => ['Предупреждение: текст', 'Статусы'],
        'bad' => ['Ошибка: цвет', 'Статусы'],
        'bad-soft' => ['Ошибка: фон', 'Статусы'],
        'bad-fg' => ['Ошибка: текст', 'Статусы'],
        'info' => ['Информация: цвет', 'Статусы'],
        'info-soft' => ['Информация: фон', 'Статусы'],
        'info-fg' => ['Информация: текст', 'Статусы'],
        'vk' => ['ВКонтакте', 'Соцсети'],
        'tg' => ['Telegram', 'Соцсети'],
        'max' => ['MAX', 'Соцсети'],
        'ig' => ['Instagram', 'Соцсети'],
    ];

    /** Standard values, as in `resources/css/app.css`. */
    public const LIGHT = [
        'bg' => '247 248 252', 'surface' => '255 255 255', 'sunken' => '238 240 248', 'line' => '223 227 239', 'fg' => '20 26 46', 'muted' => '88 96 121',
        'p' => '79 70 229', 'p-hover' => '67 56 202', 'p-fg' => '255 255 255', 'p-soft' => '238 237 253', 'p-softfg' => '55 48 163', 'p-text' => '67 56 202',
        'ok' => '21 128 61', 'ok-soft' => '220 252 231', 'ok-fg' => '20 83 45',
        'warn' => '180 83 9', 'warn-soft' => '254 243 199', 'warn-fg' => '120 53 15',
        'bad' => '194 39 45', 'bad-soft' => '254 226 226', 'bad-fg' => '127 29 29',
        'info' => '29 111 184', 'info-soft' => '219 234 254', 'info-fg' => '30 58 138',
        'vk' => '0 119 255', 'tg' => '34 158 217', 'max' => '124 77 255', 'ig' => '225 48 108',
    ];

    public const DARK = [
        'bg' => '14 17 31', 'surface' => '22 26 45', 'sunken' => '29 34 56', 'line' => '43 49 82', 'fg' => '238 240 250', 'muted' => '163 171 198',
        'p' => '124 117 255', 'p-hover' => '150 144 255', 'p-fg' => '14 17 31', 'p-soft' => '36 36 79', 'p-softfg' => '199 196 255', 'p-text' => '165 160 255',
        'ok' => '74 222 128', 'ok-soft' => '18 48 31', 'ok-fg' => '134 239 172',
        'warn' => '251 191 36', 'warn-soft' => '58 42 11', 'warn-fg' => '252 211 77',
        'bad' => '248 113 113', 'bad-soft' => '61 21 23', 'bad-fg' => '252 165 165',
        'info' => '96 165 250', 'info-soft' => '16 38 63', 'info-fg' => '147 197 253',
        'vk' => '0 119 255', 'tg' => '34 158 217', 'max' => '124 77 255', 'ig' => '225 48 108',
    ];

    /** Pairs that must stay readable: [text token, background token, minimum contrast, description]. */
    private const PAIRS = [
        ['fg', 'bg', 4.5, 'Основной текст на фоне страницы'],
        ['fg', 'surface', 4.5, 'Основной текст на карточках'],
        ['muted', 'bg', 4.5, 'Вторичный текст на фоне страницы'],
        ['muted', 'surface', 4.5, 'Вторичный текст на карточках'],
        ['p-fg', 'p', 4.5, 'Текст на акцентной кнопке'],
        ['p-text', 'surface', 4.5, 'Ссылки на карточках'],
        ['p-softfg', 'p-soft', 4.5, 'Текст на светлом акценте'],
        ['ok-fg', 'ok-soft', 4.5, 'Текст «успех»'],
        ['warn-fg', 'warn-soft', 4.5, 'Текст «предупреждение»'],
        ['bad-fg', 'bad-soft', 4.5, 'Текст «ошибка»'],
        ['info-fg', 'info-soft', 4.5, 'Текст «информация»'],
    ];

    public function __construct(private readonly Settings $settings)
    {
    }

    /**
     * What the owner has changed: only tokens that differ from the standard.
     *
     * @return array{light: array<string, string>, dark: array<string, string>}
     */
    public function overrides(): array
    {
        $stored = $this->settings->get(self::SETTING, []);
        $result = ['light' => [], 'dark' => []];
        foreach (['light' => self::LIGHT, 'dark' => self::DARK] as $mode => $defaults) {
            $values = is_array($stored) && is_array($stored[$mode] ?? null) ? $stored[$mode] : [];
            foreach ($values as $token => $triplet) {
                if (is_string($token) && is_string($triplet) && isset($defaults[$token]) && self::validTriplet($triplet) && $triplet !== $defaults[$token]) {
                    $result[$mode][$token] = $triplet;
                }
            }
        }

        return $result;
    }

    /**
     * Every token of a theme with the value in force.
     *
     * @param 'light'|'dark' $mode
     * @return array<string, string> token => `R G B`
     */
    public function effective(string $mode): array
    {
        return $this->overrides()[$mode] + ($mode === 'dark' ? self::DARK : self::LIGHT);
    }

    public function isCustomised(): bool
    {
        $o = $this->overrides();

        return $o['light'] !== [] || $o['dark'] !== [];
    }

    /**
     * Save the colours typed in the form (`#rrggbb`). Anything else is ignored; values equal to the standard are not stored.
     *
     * @param array<string, mixed> $light token => hex
     * @param array<string, mixed> $dark token => hex
     * @return array{changed: list<string>, invalid: list<string>} tokens (`light.bg`) that now differ from before, and those rejected
     */
    public function save(array $light, array $dark, ?int $actorId): array
    {
        $before = $this->overrides();
        $new = ['light' => [], 'dark' => []];
        $invalid = [];
        foreach (['light' => [$light, self::LIGHT], 'dark' => [$dark, self::DARK]] as $mode => [$input, $defaults]) {
            foreach ($defaults as $token => $default) {
                $hex = $input[$token] ?? null;
                if ($hex === null || $hex === '') {
                    continue;
                }
                $triplet = is_string($hex) ? self::hexToTriplet($hex) : null;
                if ($triplet === null) {
                    $invalid[] = $mode . '.' . $token;
                    continue;
                }
                if ($triplet !== $default) {
                    $new[$mode][$token] = $triplet;
                }
            }
        }
        $changed = [];
        foreach (['light', 'dark'] as $mode) {
            foreach (array_unique([...array_keys($before[$mode]), ...array_keys($new[$mode])]) as $token) {
                if (($before[$mode][$token] ?? null) !== ($new[$mode][$token] ?? null)) {
                    $changed[] = $mode . '.' . $token;
                }
            }
        }
        if ($new['light'] === [] && $new['dark'] === []) {
            $this->settings->forget(self::SETTING);
        } else {
            $this->settings->set(self::SETTING, $new, $actorId);
        }

        return ['changed' => $changed, 'invalid' => $invalid];
    }

    /**
     * Back to the standard colours.
     *
     * @param 'all'|'light'|'dark' $scope
     * @return int how many tokens were different before
     */
    public function reset(string $scope, ?int $actorId): int
    {
        $before = $this->overrides();
        $count = 0;
        $keep = $before;
        foreach (['light', 'dark'] as $mode) {
            if ($scope === 'all' || $scope === $mode) {
                $count += count($before[$mode]);
                $keep[$mode] = [];
            }
        }
        if ($keep['light'] === [] && $keep['dark'] === []) {
            $this->settings->forget(self::SETTING);
        } else {
            $this->settings->set(self::SETTING, $keep, $actorId);
        }

        return $count;
    }

    /**
     * The override stylesheet (empty when nothing was changed).
     */
    public function css(): string
    {
        $o = $this->overrides();
        $block = static function (array $values): string {
            $out = '';
            foreach ($values as $token => $triplet) {
                $out .= '--' . $token . ': ' . $triplet . '; ';
            }

            return trim($out);
        };
        $css = '';
        if ($o['light'] !== []) {
            $css .= ':root { ' . $block($o['light']) . " }\n";
        }
        if ($o['dark'] !== []) {
            $css .= ":root[data-theme='dark'] { " . $block($o['dark']) . " }\n";
            $css .= "@media (prefers-color-scheme: dark) { :root:not([data-theme='light']) { " . $block($o['dark']) . " } }\n";
        }

        return $css;
    }

    /**
     * A short hash of the override for the stylesheet URL (`/theme.css?v=…`); empty when nothing is changed, so no link is rendered.
     */
    public function version(): string
    {
        return $this->isCustomised() ? substr(hash('sha256', $this->css()), 0, 10) : '';
    }

    /**
     * Contrast of the pairs that must stay readable, in a theme as it is in force (or as the given colours would make it).
     *
     * @param array<string, string>|null $colors token => `R G B`; the colours in force when null
     * @param 'light'|'dark' $mode
     * @return list<array{text: string, background: string, label: string, ratio: float, ok: bool}>
     */
    public function contrast(string $mode, ?array $colors = null): array
    {
        $colors ??= $this->effective($mode);
        $rows = [];
        foreach (self::PAIRS as [$text, $background, $minimum, $label]) {
            $ratio = self::ratio($colors[$text], $colors[$background]);
            $rows[] = ['text' => $text, 'background' => $background, 'label' => $label, 'ratio' => round($ratio, 2), 'ok' => $ratio >= $minimum];
        }

        return $rows;
    }

    public static function validTriplet(string $triplet): bool
    {
        if (preg_match('/^(\d{1,3}) (\d{1,3}) (\d{1,3})$/', $triplet, $m) !== 1) {
            return false;
        }

        return (int) $m[1] <= 255 && (int) $m[2] <= 255 && (int) $m[3] <= 255;
    }

    /**
     * `#4f46e5` to `79 70 229`; null for anything that is not a six-digit hex colour.
     */
    public static function hexToTriplet(string $hex): ?string
    {
        if (preg_match('/^#([0-9a-fA-F]{2})([0-9a-fA-F]{2})([0-9a-fA-F]{2})$/', trim($hex), $m) !== 1) {
            return null;
        }

        return hexdec($m[1]) . ' ' . hexdec($m[2]) . ' ' . hexdec($m[3]);
    }

    public static function tripletToHex(string $triplet): string
    {
        $parts = array_map('intval', explode(' ', $triplet));

        return sprintf('#%02x%02x%02x', $parts[0], $parts[1] ?? 0, $parts[2] ?? 0);
    }

    /**
     * WCAG contrast ratio of two `R G B` colours (1 to 21).
     */
    public static function ratio(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    private static function luminance(string $triplet): float
    {
        $channels = array_map(static function (string $part): float {
            $c = ((int) $part) / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, explode(' ', $triplet));

        return 0.2126 * $channels[0] + 0.7152 * ($channels[1] ?? 0) + 0.0722 * ($channels[2] ?? 0);
    }
}
