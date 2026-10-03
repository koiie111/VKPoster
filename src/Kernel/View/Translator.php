<?php

declare(strict_types=1);

namespace App\Kernel\View;

/**
 * Minimal translation layer used by `t()`. Keys are either dotted ids (`validation.required`) or the
 * Russian source text itself; a missing key returns the key unchanged, so UI never shows blanks.
 * `:name` placeholders are replaced from `$params`. The catalog is `resources/lang/<locale>.php`.
 */
final class Translator
{
    /** @var array<string, string> */
    private array $catalog;

    public function __construct(string $langDir, string $locale = 'ru')
    {
        $file = rtrim($langDir, '/') . '/' . $locale . '.php';
        /** @var array<string, string> $catalog */
        $catalog = is_file($file) ? require $file : [];
        $this->catalog = $catalog;
    }

    /**
     * @param array<string, scalar> $params
     */
    public function t(string $key, array $params = []): string
    {
        $text = $this->catalog[$key] ?? $key;
        $replace = [];
        foreach ($params as $name => $value) {
            $replace[':' . $name] = (string) $value;
        }

        return strtr($text, $replace);
    }
}
